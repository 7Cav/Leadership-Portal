<?php

namespace App\Kpi;

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
 * (`TrooperRepository::assignedWithin`). Per trooper, over the window:
 *
 * - **RC** (MVP amendment, #23): weeks with a signed rollcall (`will_attend` or `cannot_attend`)
 *   ÷ assigned weeks — all {@see WINDOW_WEEKS}, whether or not a practice was held.
 * - **SP**: practices marked present, makeup or attached ÷ practices their primary section held
 *   (AD-14's counting SP). A week with no practice row is not eligible; a held practice the
 *   trooper is not marked for is eligible but not attended.
 * - **missed**: practices explicitly marked `missed`. An unmarked practice is never read as a miss
 *   — the service does not assert absence it was not told about (the AD-4 fail-safe).
 * - An **LOA-covered** week leaves both denominators and is never a miss.
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
    ) {}

    /**
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function forUnit(Unit $unit, CarbonInterface $weekStart): UnitKpis
    {
        $byTrooper = $this->trooperAttendance($unit, $weekStart);

        return new UnitKpis(
            strength: $byTrooper->count(),
            rc30d: new Ratio($byTrooper->sum(fn (TrooperAttendance $t) => $t->rollcall->numerator), $byTrooper->sum(fn (TrooperAttendance $t) => $t->rollcall->denominator)),
            sp30d: new Ratio($byTrooper->sum(fn (TrooperAttendance $t) => $t->practice->numerator), $byTrooper->sum(fn (TrooperAttendance $t) => $t->practice->denominator)),
            atRisk: $byTrooper->filter(fn (TrooperAttendance $t) => $t->atRisk())->count(),
            onLoa: $byTrooper->filter(fn (TrooperAttendance $t) => $t->onLoa)->count(),
            makeups: $byTrooper->sum(fn (TrooperAttendance $t) => $t->makeups),
        );
    }

    /**
     * Every trooper assigned within the unit's subtree, keyed and ordered by trooper id — the
     * per-trooper rows `forUnit()` sums, for a roster or at-risk flag to reuse. Two queries
     * regardless of subtree size.
     *
     * @return Collection<int, TrooperAttendance>
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function trooperAttendance(Unit $unit, CarbonInterface $weekStart): Collection
    {
        $weekStart = $this->assertWeekStart($weekStart);
        $marks = $this->attendance
            ->countingMarks($unit, $weekStart->subWeeks(self::WINDOW_WEEKS - 1), $weekStart)
            ->groupBy(fn (CountingMark $mark) => $mark->trooperId);

        return $this->troopers->assignedWithin($unit)->mapWithKeys(fn (int $trooperId) => [
            $trooperId => $this->forTrooper($trooperId, $marks->get($trooperId, collect()), $weekStart),
        ]);
    }

    /**
     * @param  Collection<int, CountingMark>  $marks  this trooper's counting marks in the window
     */
    private function forTrooper(int $trooperId, Collection $marks, CarbonImmutable $weekStart): TrooperAttendance
    {
        [$loa, $eligible] = $marks->partition(fn (CountingMark $mark) => $this->isLoaCovered($mark));
        $thisWeek = $marks->filter(fn (CountingMark $mark) => $mark->weekStart->equalTo($weekStart));

        return new TrooperAttendance(
            trooperId: $trooperId,
            rollcall: new Ratio(
                $eligible->filter(fn (CountingMark $mark) => $mark->rollcall !== RollcallSignature::Unsigned)->count(),
                self::WINDOW_WEEKS - $loa->count(),
            ),
            practice: new Ratio(
                $eligible->filter(fn (CountingMark $mark) => in_array($mark->status, self::ATTENDED, true))->count(),
                $eligible->count(),
            ),
            missed: $eligible->filter(fn (CountingMark $mark) => $mark->status === AttendanceStatus::Missed)->count(),
            onLoa: $thisWeek->contains(fn (CountingMark $mark) => $this->isLoaCovered($mark)),
            makeups: $thisWeek->filter(fn (CountingMark $mark) => $mark->status === AttendanceStatus::Makeup)->count(),
        );
    }

    /**
     * Whether LOA covers this trooper-week. MVP: the week's mark is `loa` — there is no LOA table
     * yet, so the only LOA coverage is a mark against a held practice. Epic 4 replaces this one
     * predicate with coverage from the synced `loas` table (covering weeks with no practice row
     * too), and adds AD-4's fail-safe there: a `needs_review` LOA must suppress at-risk for the
     * trooper rather than let the week count as a miss.
     */
    private function isLoaCovered(CountingMark $mark): bool
    {
        return $mark->status === AttendanceStatus::Loa;
    }

    /** Validates rather than derives: the Saturday 00:00 UTC rule lives in `WeekOf` (AD-11). */
    private function assertWeekStart(CarbonInterface $weekStart): CarbonImmutable
    {
        $boundary = WeekOf::start($weekStart);

        if (! $boundary->equalTo($weekStart)) {
            throw new InvalidArgumentException(
                "week_start must be a Saturday 00:00 UTC (AD-11); got {$weekStart->toIso8601String()}.",
            );
        }

        return $boundary;
    }
}
