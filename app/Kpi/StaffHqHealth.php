<?php

namespace App\Kpi;

use App\Models\Unit;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;

/**
 * The health of a platoon, company or battalion HQ's unit for one Cav week, as the staff report pages show it beside the
 * staff: its sections' SP and roll call rates ({@see KpiService::forUnit()}, the same numbers as Home and the roster),
 * its headcount against how many are in roll call ({@see StrengthTrends}), the at-risk count, and a trend of the
 * {@see TREND_WEEKS} weeks ending with the week. Whether the staff kept to the one practice rule is the caller's: it
 * holds the staff reports.
 *
 * Bounded: one `forUnit()` per trend week (a fixed number of queries each) and one strength read (three queries),
 * whatever the size of the unit. Callers pass only a unit the viewer reads (AD-8).
 */
final class StaffHqHealth
{
    public const TREND_WEEKS = 8;

    public function __construct(
        private readonly KpiService $kpis,
        private readonly StrengthTrends $strength,
    ) {}

    /**
     * @return array{unit: array{id: int, label: string}, week: string, sp: ?float, rc: ?float, headcount: ?int, inRollcall: ?int, atRisk: int, trend: list<array{week: string, sp: ?float, rc: ?float, headcount: ?int, inRollcall: ?int, atRisk: int}>}
     */
    public function forUnit(Unit $unit, CarbonImmutable $weekStart, ?CarbonImmutable $now = null): array
    {
        $weekStart = WeekOf::requireStart($weekStart);
        $running = WeekOf::start($now ?? CarbonImmutable::now());
        $first = $weekStart->subWeeks(self::TREND_WEEKS - 1);
        $span = (int) round($first->diffInWeeks($running, true)) + 1;
        $strength = collect($this->strength->forUnit($unit, max($span, 1), $now)['weeks'])->keyBy('week');

        $trend = [];
        for ($week = $first; $week->lessThanOrEqualTo($weekStart); $week = $week->addWeek()) {
            $kpis = $this->kpis->forUnit($unit, $week);
            $point = $strength->get($week->toDateString());
            $trend[] = [
                'week' => $week->toDateString(),
                'sp' => $kpis->sp30d->value(),
                'rc' => $kpis->rc30d->value(),
                'headcount' => $point['headcount'] ?? null,
                'inRollcall' => $point['strength'] ?? null,
                'atRisk' => $kpis->atRisk,
            ];
        }

        $last = $trend[array_key_last($trend)];

        return [
            'unit' => ['id' => $unit->id, 'label' => $unit->callsign ?? $unit->name],
            'week' => $weekStart->toDateString(),
            'sp' => $last['sp'],
            'rc' => $last['rc'],
            'headcount' => $last['headcount'],
            'inRollcall' => $last['inRollcall'],
            'atRisk' => $last['atRisk'],
            'trend' => $trend,
        ];
    }
}
