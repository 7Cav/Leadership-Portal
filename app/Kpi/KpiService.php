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
use App\Repositories\TrooperRepository;
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
 * (`TrooperRepository::assignedWithin`) — the cache has no placement history, so a trooper who
 * changed section mid-window is measured entirely against their current section, and a past week
 * recomputed after the move can differ from the snapshot frozen before it. Per trooper, over the
 * window:
 *
 * - **RC** (MVP amendment, #23): weeks with a signed rollcall (`will_attend` or `cannot_attend`)
 *   ÷ assigned weeks — all {@see WINDOW_WEEKS}, whether or not a practice was held. A signature
 *   is recorded against the section-week's practice row, so a week with no row counts unsigned.
 * - **SP**: practices marked present, makeup or attached ÷ the *held* practices (one row per
 *   section-week, AD-14's counting SP) of their *current* primary section. A practice is held
 *   once any attendance row on it carries a leader's SP mark (`loa`, derived from LOA data,
 *   does not count); a week with no practice row, or a row that
 *   carries only rollcall signatures, is not eligible. In a held practice a trooper left
 *   unmarked is eligible but not attended — the leader saw the practice happen. A mark against
 *   another section's practice never counts, a makeup included.
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

    public function __construct(
        private readonly TrooperRepository $troopers,
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
     * per-trooper rows, split by the section each trooper is placed in, in three queries however
     * many sections (5.8's roll-up of a whole battalion): marks, placement and LOA coverage (4.8). A section with no
     * assigned trooper is absent; its KPIs are `UnitKpis::none()`. Equal, section by section, to `forUnit()`: a
     * trooper's marks only ever count against their own section's practice.
     *
     * @return Collection<int, UnitKpis>
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function forSectionsWithin(Unit $unit, CarbonInterface $weekStart): Collection
    {
        $weekStart = WeekOf::requireStart($weekStart);
        $marks = $this->marksByTrooper($unit, $weekStart);
        $sections = $this->troopers->sectionsOfAssignedWithin($unit);
        $covers = $this->coversFor($sections->keys(), $weekStart);

        return $sections
            ->map(fn (int $sectionId, int $trooperId) => [
                'section' => $sectionId,
                'row' => $this->forTrooper($trooperId, $marks->get($trooperId, collect()), $weekStart, $covers[$trooperId]),
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
     * per-trooper rows `forUnit()` sums, for a roster or at-risk flag to reuse. Three queries
     * regardless of subtree size.
     *
     * @return Collection<int, TrooperAttendance>
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function trooperAttendance(Unit $unit, CarbonInterface $weekStart): Collection
    {
        $weekStart = WeekOf::requireStart($weekStart);
        $marks = $this->marksByTrooper($unit, $weekStart);

        $troopers = $this->troopers->assignedWithin($unit);
        $covers = $this->coversFor($troopers, $weekStart);

        return $troopers->mapWithKeys(fn (int $trooperId) => [
            $trooperId => $this->forTrooper($trooperId, $marks->get($trooperId, collect()), $weekStart, $covers[$trooperId]),
        ]);
    }

    /**
     * The window's counting marks of every trooper assigned within `$unit`, grouped by trooper.
     *
     * @return Collection<int, Collection<int, CountingMark>>
     */
    private function marksByTrooper(Unit $unit, CarbonImmutable $weekStart): Collection
    {
        return $this->attendance
            ->countingMarks($unit, $weekStart->subWeeks(self::WINDOW_WEEKS - 1), $weekStart)
            ->groupBy(fn (CountingMark $mark) => $mark->trooperId);
    }

    /**
     * The LOA coverage of these troopers for each week of the window ending with `$weekStart`, in one query: the
     * single definition (4.4) every per-trooper row is built from, so `forUnit()` and `forSectionsWithin()` can
     * never disagree about who is on leave.
     *
     * @param  iterable<int>  $trooperIds
     * @return array<int, array<string, LoaCover>>
     */
    private function coversFor(iterable $trooperIds, CarbonImmutable $weekStart): array
    {
        return $this->loa->forTroopersOverWeeks($trooperIds, $weekStart->subWeeks(self::WINDOW_WEEKS - 1), self::WINDOW_WEEKS);
    }

    /**
     * @param  Collection<int, CountingMark>  $marks  this trooper's counting marks in the window
     * @param  array<string, LoaCover>  $covers  this trooper's LOA coverage per window week, keyed by `week_start` date
     */
    private function forTrooper(int $trooperId, Collection $marks, CarbonImmutable $weekStart, array $covers): TrooperAttendance
    {
        // A week leaves the window when leave covers it, or when a leave that could not be read might: either way
        // the trooper is not counted as missing it (AD-4). `$loaWeeks` is those weeks, whether or not a practice
        // row exists for them, so the rollcall denominator drops them too.
        $loaWeeks = collect($covers)
            ->filter(fn ($cover) => $cover->suppressesAtRisk())
            ->keys()
            ->merge($marks->filter(fn (CountingMark $mark) => $this->isLoaCovered($mark))->map(fn (CountingMark $mark) => $mark->weekStart->toDateString()))
            ->unique();
        $eligible = $marks->reject(fn (CountingMark $mark) => $loaWeeks->contains($mark->weekStart->toDateString()));
        $thisWeek = $marks->filter(fn (CountingMark $mark) => $mark->weekStart->equalTo($weekStart));
        $missed = $eligible->filter(fn (CountingMark $mark) => $mark->status === AttendanceStatus::Missed);

        return new TrooperAttendance(
            trooperId: $trooperId,
            rollcall: new Ratio(
                $eligible->filter(fn (CountingMark $mark) => $mark->rollcall !== RollcallSignature::Unsigned)->count(),
                self::WINDOW_WEEKS - $loaWeeks->count(),
            ),
            practice: new Ratio(
                $eligible->filter(fn (CountingMark $mark) => in_array($mark->status, self::ATTENDED, true))->count(),
                $eligible->filter(fn (CountingMark $mark) => $mark->held)->count(),
            ),
            missed: $missed->count(),
            // Covered, not merely suppressed: a leave that needs review is never asserted as "on LOA".
            onLoa: ($covers[$weekStart->toDateString()]->state === LoaCoverageState::Covered)
                || $thisWeek->contains(fn (CountingMark $mark) => $this->isLoaCovered($mark)),
            makeups: $thisWeek->filter(fn (CountingMark $mark) => $mark->status === AttendanceStatus::Makeup)->count(),
            missedWeeks: $missed->map(fn (CountingMark $mark) => $mark->weekStart->toDateString())->sort()->values()->all(),
        );
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
