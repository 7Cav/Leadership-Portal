<?php

namespace App\Kpi;

use App\Loa\LoaCover;
use App\Loa\LoaCoverage;
use App\Loa\LoaCoverageState;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\Unit;
use App\Repositories\AttendanceRepository;
use App\Repositories\CountingMark;
use App\Repositories\CountingWindow;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The one place the six canonical KPIs are defined and computed (AD-21). The dashboard, the
 * weekly report, the roll-up and Epic 4's at-risk flag all read them from here; nothing else
 * re-derives a KPI. Read-only, and deterministic for unchanged data, so a live dashboard and a
 * frozen report snapshot of the same unit-week agree.
 *
 * It computes for whatever unit it is handed: callers pass only units the ScopeResolver permits
 * (AD-8). The window is the trailing {@see WINDOW_WEEKS} whole Cav weeks ending with — and
 * including — `week_start`, stepped in whole weeks from a `WeekOf` boundary (AD-11).
 *
 * The population is every trooper currently assigned within the unit's subtree
 * (`TrooperRepository::assignedWithin`). Each trooper's weeks are read against the section they
 * were placed in that week (`TrooperPlacementRepository`, decided 2026-10-27: after a transfer
 * the new leadership sees the trooper's signing, practice marks and LOAs from before the move),
 * so their marks at the old section still count and the new section's practices from before
 * they joined never do. A unit's KPIs stay the sum of its current members' rows, their weeks
 * elsewhere included: the same rows the roster shows per trooper, so one definition (AD-21), and
 * a sent report keeps the numbers frozen when it was sent. A trooper with no placement history
 * reads as placed in their current section for every week.
 *
 * That is the running week (and any later one). A week that has ended is measured over who was in the
 * unit that week instead (the 2026-10-29 audit, `AttendanceRepository::countingWindowAsPlaced`): each
 * trooper the placement history puts in a section under it when the week ended, a trooper who left the
 * Cav after the week opened included, and each row counts in that section. So a move or a departure
 * between the end of a week and its report changes neither the week's report nor its rollcall, which
 * lists the same troopers. Per trooper, over the window:
 *
 * - **RC** (MVP amendment, #23): weeks with a signed rollcall (`will_attend` or `cannot_attend`)
 *   ÷ assigned weeks: the window weeks they were placed in a section, whether or not a practice
 *   was held (all {@see WINDOW_WEEKS} for a trooper in a section the whole window). A signature
 *   is recorded against the section-week's practice row, so a week with no row counts unsigned.
 * - **SP**: practices marked present, makeup or attached ÷ the *held* practices (one row per
 *   section-week, AD-14's counting SP) of the section they were placed in that week. A practice is held
 *   once any attendance row on it carries a leader's SP mark (`loa`, derived from LOA data,
 *   does not count, and neither does an automatic makeup); a week with no practice row, or a row that
 *   carries only rollcall signatures, is not eligible. In a held practice a trooper left
 *   unmarked is eligible but not attended — the leader saw the practice happen. A week the
 *   trooper attended is always eligible for them, held or not: a trooper the capture saw at
 *   another section's practice carries an automatic makeup on their own section's practice
 *   (decided 2026-10-23), which credits their SP like a makeup a leader marks by hand, even in a
 *   week their own section did not practise. A mark against another section's practice row never
 *   counts, a makeup included; the makeup is always recorded on the trooper's own section's row.
 * - **missed**: practices explicitly marked `missed`. An unmarked trooper is never read as a miss
 *   — the service does not assert absence it was not told about (the AD-4 fail-safe).
 * - An **LOA-covered** week leaves both denominators and is never a miss. Coverage is `LoaCoverage` (4.4), the
 *   one definition, whether or not a practice row exists for the week. A week where a leave that could not be
 *   read (`needs_review`) may apply is left out the same way: it is not a miss and not in a denominator, but it
 *   is not counted as `on_loa` either, since nothing says the trooper is away (AD-4).
 *
 * A unit's KPIs are the sum over its troopers: strength = troopers, rc_30d / sp_30d = summed
 * ratios, at_risk = troopers with ≥ `TrooperAttendance::AT_RISK_MISSES` misses, on_loa = troopers
 * LOA-covered in the `week_start` week, makeups = makeup marks in that week.
 */
final class KpiService
{
    public const WINDOW_WEEKS = 4;

    private const ATTENDED = [AttendanceStatus::Present, AttendanceStatus::Makeup, AttendanceStatus::Attached];

    /** Attended at their own section's practice ({@see ownSectionPractice()}): {@see ATTENDED} without the makeup. */
    private const ATTENDED_AT_OWN = [AttendanceStatus::Present, AttendanceStatus::Attached];

    public function __construct(
        private readonly AttendanceRepository $attendance,
        private readonly LoaCoverage $loa,
    ) {}

    /**
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function forUnit(Unit $unit, CarbonInterface $weekStart): UnitKpis
    {
        return $this->sum($this->trooperAttendance($unit, $weekStart));
    }

    /**
     * `forUnit()` for every section under `$unit` at once, keyed by section id — the same
     * per-trooper rows, split by the section each trooper is placed in, in a fixed number of queries however
     * many sections (5.8's roll-up of a whole battalion): where everyone was placed, their marks, and LOA coverage (4.8). A section with no
     * assigned trooper is absent; its KPIs are `UnitKpis::none()`. Equal, section by section, to `forUnit()`: a
     * trooper's row is counted in their current section (for a week that has ended, the section they ended it in),
     * their weeks elsewhere included.
     *
     * @return Collection<int, UnitKpis>
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function forSectionsWithin(Unit $unit, CarbonInterface $weekStart): Collection
    {
        $weekStart = WeekOf::requireStart($weekStart);
        $window = $this->window($unit, $weekStart);
        $marks = $this->byTrooper($window);
        $sections = collect($window->sections);
        $covers = $this->coversFor($sections->keys(), $weekStart);

        return $sections
            ->map(fn (int $sectionId, int $trooperId) => [
                'section' => $sectionId,
                'row' => $this->forTrooper($trooperId, $sectionId, $marks->get($trooperId, collect()), $weekStart, $covers[$trooperId], $window->placements->weeksOf($trooperId)),
            ])
            ->groupBy('section')
            ->map(fn (Collection $rows) => $this->sum($rows->pluck('row')));
    }

    /**
     * The unit KPIs of a set of troopers' rows from {@see trooperAttendance()} — how `forUnit()`
     * composes its subtree, exposed so a screen holding one subtree's rows (the roster, 2.8) can
     * total each platoon and section without a query per group or a second definition of the sum.
     *
     * @param  Collection<int, TrooperAttendance>  $byTrooper
     */
    public function sum(Collection $byTrooper): UnitKpis
    {
        return new UnitKpis(
            strength: $byTrooper->count(),
            rc30d: $byTrooper->reduce(fn (Ratio $sum, TrooperAttendance $t) => $sum->plus($t->rollcall), new Ratio(0, 0)),
            sp30d: $byTrooper->reduce(fn (Ratio $sum, TrooperAttendance $t) => $sum->plus($t->practice), new Ratio(0, 0)),
            atRisk: $byTrooper->filter(fn (TrooperAttendance $t) => $t->atRisk())->count(),
            onLoa: $byTrooper->filter(fn (TrooperAttendance $t) => $t->onLoa)->count(),
            makeups: $byTrooper->sum(fn (TrooperAttendance $t) => $t->makeups),
        );
    }

    /**
     * Every trooper assigned within the unit's subtree, keyed and ordered by trooper id — the
     * per-trooper rows `forUnit()` sums, for a roster or at-risk flag to reuse. A fixed number of
     * queries regardless of subtree size.
     *
     * @return Collection<int, TrooperAttendance>
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function trooperAttendance(Unit $unit, CarbonInterface $weekStart): Collection
    {
        $weekStart = WeekOf::requireStart($weekStart);
        $window = $this->window($unit, $weekStart);
        $marks = $this->byTrooper($window);
        $sections = collect($window->sections);
        $covers = $this->coversFor($sections->keys(), $weekStart);

        return $sections->map(fn (int $sectionId, int $trooperId) => $this->forTrooper(
            $trooperId, $sectionId, $marks->get($trooperId, collect()), $weekStart, $covers[$trooperId], $window->placements->weeksOf($trooperId),
        ));
    }

    /**
     * A trooper's attendance at their own section's practice, for the Joint Service Achievement Medal's "while still
     * consistently attending their own" (decided by the product owner 2026-10-23), keyed by trooper id, over the
     * trailing `$windowWeeks` Cav weeks ending with `$weekStart`. It is the SP above with one difference: a makeup is
     * never attendance at their own section. A makeup (by hand or automatic) answers the weekly accountability, one
     * section practice or a makeup, and so counts in the SP above; here it never counts as attended, so:
     *
     * - attended: `present` or `attached` at their own section's practice;
     * - eligible: their own section's practice was held that week (AD-14's `held`, the same rule as above), whether
     *   they attended it, missed it or made it up elsewhere; a week their own section did not practise stays out, even
     *   when they made it up, so visits to other sections can never stand in for their own;
     * - a week leave covers (`LoaCoverage`, or an `loa` mark) leaves the ratio exactly as above.
     *
     * For troopers from many sections at once in two queries (their counting marks and their leave), whichever
     * sections they belong to. A trooper who is departed, unplaced or unknown gets `0/0`, which says nothing.
     *
     * @param  iterable<int>  $trooperIds
     * @return Collection<int, Ratio>
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC, or `$windowWeeks` is below 1.
     */
    public function ownSectionPractice(iterable $trooperIds, CarbonInterface $weekStart, int $windowWeeks): Collection
    {
        if ($windowWeeks < 1) {
            throw new InvalidArgumentException("The KPI window must span at least one Cav week, got {$windowWeeks}.");
        }

        $weekStart = WeekOf::requireStart($weekStart);
        $ids = collect($trooperIds)->map(fn ($id) => (int) $id)->unique()->sort()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $marks = $this->attendance
            ->countingMarksOf($ids->all(), $weekStart->subWeeks($windowWeeks - 1), $weekStart)
            ->groupBy(fn (CountingMark $mark) => $mark->trooperId);
        $covers = $this->coversFor($ids, $weekStart, $windowWeeks);

        return $ids->mapWithKeys(function (int $trooperId) use ($marks, $covers) {
            $own = $marks->get($trooperId, collect());
            $loaWeeks = $this->loaWeeks($own, $covers[$trooperId] ?? []);
            $eligible = $own->reject(fn (CountingMark $mark) => $loaWeeks->contains($mark->weekStart->toDateString()));

            return [$trooperId => new Ratio(
                $eligible->filter(fn (CountingMark $mark) => $mark->held && in_array($mark->status, self::ATTENDED_AT_OWN, true))->count(),
                $eligible->filter(fn (CountingMark $mark) => $mark->held)->count(),
            )];
        });
    }

    /**
     * The window's counting marks of every trooper assigned within `$unit`, with each trooper's current section and the
     * weeks they were in a section: one read of the facts (two queries).
     */
    private function window(Unit $unit, CarbonImmutable $weekStart): CountingWindow
    {
        $first = $weekStart->subWeeks(self::WINDOW_WEEKS - 1);

        // A week that has ended is measured over who was in the unit that week (the 2026-10-29 audit), so its report
        // agrees with its rollcall after a move or a departure; the running week over who is assigned now.
        return $weekStart->lessThan(WeekOf::start(CarbonImmutable::now()))
            ? $this->attendance->countingWindowAsPlaced($unit, $first, $weekStart)
            : $this->attendance->countingWindow($unit, $first, $weekStart);
    }

    /**
     * @return Collection<int, Collection<int, CountingMark>>
     */
    private function byTrooper(CountingWindow $window): Collection
    {
        return $window->marks->groupBy(fn (CountingMark $mark) => $mark->trooperId);
    }

    /**
     * The LOA coverage of these troopers for each week of the window ending with `$weekStart`, in one query: the
     * single definition (4.4) every per-trooper row is built from, so `forUnit()` and `forSectionsWithin()` can
     * never disagree about who is on leave.
     *
     * @param  iterable<int>  $trooperIds
     * @return array<int, array<string, LoaCover>>
     */
    private function coversFor(iterable $trooperIds, CarbonImmutable $weekStart, int $windowWeeks = self::WINDOW_WEEKS): array
    {
        return $this->loa->forTroopersOverWeeks($trooperIds, $weekStart->subWeeks($windowWeeks - 1), $windowWeeks);
    }

    /**
     * @param  Collection<int, CountingMark>  $marks  this trooper's counting marks in the window
     * @param  array<string, LoaCover>  $covers  this trooper's LOA coverage per window week, keyed by `week_start` date
     * @param  list<string>  $assignedWeeks  the window weeks they were placed in a section
     */
    private function forTrooper(int $trooperId, int $sectionId, Collection $marks, CarbonImmutable $weekStart, array $covers, array $assignedWeeks): TrooperAttendance
    {
        // `$loaWeeks` holds the weeks leave takes out whether or not a practice row exists for them, so the rollcall
        // denominator drops them too.
        $loaWeeks = $this->loaWeeks($marks, $covers);
        $eligible = $marks->reject(fn (CountingMark $mark) => $loaWeeks->contains($mark->weekStart->toDateString()));
        $thisWeek = $marks->filter(fn (CountingMark $mark) => $mark->weekStart->equalTo($weekStart));
        $missed = $eligible->filter(fn (CountingMark $mark) => $mark->status === AttendanceStatus::Missed);

        return new TrooperAttendance(
            trooperId: $trooperId,
            rollcall: new Ratio(
                $eligible->filter(fn (CountingMark $mark) => $mark->rollcall !== RollcallSignature::Unsigned)->count(),
                // Only the weeks they were in a section: a week before they joined one is not theirs to sign.
                count(array_diff($assignedWeeks, $loaWeeks->all())),
            ),
            practice: new Ratio(
                $eligible->filter(fn (CountingMark $mark) => in_array($mark->status, self::ATTENDED, true))->count(),
                // Held, or attended by this trooper: an automatic makeup does not hold the practice for the
                // section, but the trooper who made it up still practised that week, so it counts for them.
                $eligible->filter(fn (CountingMark $mark) => $mark->held || in_array($mark->status, self::ATTENDED, true))->count(),
            ),
            missed: $missed->count(),
            // Covered, not merely suppressed: a leave that needs review is never asserted as "on LOA".
            onLoa: ($covers[$weekStart->toDateString()]->state === LoaCoverageState::Covered)
                || $thisWeek->contains(fn (CountingMark $mark) => $this->isLoaCovered($mark)),
            makeups: $thisWeek->filter(fn (CountingMark $mark) => $mark->status === AttendanceStatus::Makeup)->count(),
            missedWeeks: $missed->map(fn (CountingMark $mark) => $mark->weekStart->toDateString())->sort()->values()->all(),
            sectionId: $sectionId,
        );
    }

    /**
     * The window weeks (`week_start` dates) that leave every denominator: a week leave covers, or one a leave that
     * could not be read might cover, so the trooper is not counted as missing it (AD-4), and a week already marked
     * `loa` ({@see isLoaCovered()}).
     *
     * @param  Collection<int, CountingMark>  $marks  one trooper's counting marks in the window
     * @param  array<string, LoaCover>  $covers  that trooper's LOA coverage per window week
     * @return Collection<int, string>
     */
    private function loaWeeks(Collection $marks, array $covers): Collection
    {
        return collect($covers)
            ->filter(fn (LoaCover $cover) => $cover->suppressesAtRisk())
            ->keys()
            ->merge($marks->filter(fn (CountingMark $mark) => $this->isLoaCovered($mark))->map(fn (CountingMark $mark) => $mark->weekStart->toDateString()))
            ->unique();
    }

    /**
     * Whether this practice mark is itself an `loa` mark. LOA coverage comes from {@see LoaCoverage} (Story
     * 4.8), the single definition (AD-21), and covers a week whether or not a practice row exists for it.
     *
     * A mark of `loa` is kept as a second way a week leaves the window, and that is a deliberate, documented
     * exception, not a second definition: such a mark is a recorded fact about that practice (the rollcall
     * stamps it, and leaders have entered it by hand), so a week already marked `loa` is never counted against
     * the trooper even if the resolver later says `None` (a leave edited or removed on the forum). Since 3.4 no
     * new `loa` mark can be entered by hand: the rollcall refuses a status for a trooper the resolver covers, so
     * only older marks remain, and the resolver is what decides coverage from now on. A test pins "mark says
     * loa, resolver says none".
     */
    private function isLoaCovered(CountingMark $mark): bool
    {
        return $mark->status === AttendanceStatus::Loa;
    }
}
