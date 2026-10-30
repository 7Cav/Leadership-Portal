<?php

namespace App\Kpi;

use App\Models\Unit;
use App\Models\UnitType;
use App\Models\WeeklyReport;
use App\Repositories\TrooperPlacementRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A unit's strength week by week (the roster's Strength tab): its headcount ({@see Headcount}) and how many are in roll
 * call (troopers in its sections, the KPI strength) for each Cav week of a range ending with the running week.
 *
 * A week the unit sent its report for reads the frozen snapshot (AD-12), never a recompute. Any other week from
 * {@see HISTORY_FROM} on is rebuilt from the placement history, where each trooper ended the week. A week before the
 * history with no sent report is a gap (null), never a zero. The joins and departures ("in" and "out") are the troopers
 * who entered or left the unit's headcount between two rebuilt weeks. A sent snapshot that carries its own `joined` and
 * `left` counts gives those instead; otherwise they are null where either week is not rebuilt.
 *
 * A battalion's children also carry their own line children (its companies' platoons), so the comparison reaches one
 * level further where a company alone says little.
 *
 * Three queries whatever the range or the size of the subtree: the tree, the sent reports of every unit shown, and the
 * placement history.
 */
final class StrengthTrends
{
    /** The Cav week the placement history starts in: weeks before it are never rebuilt. */
    public const HISTORY_FROM = '2026-10-24';

    /** The ranges the page offers, in weeks ending with the running one; null is "all". */
    public const RANGES = [12, 26, 52, null];

    /** How far back "all" looks for a sent report. */
    private const ALL_WEEKS = 260;

    public function __construct(
        private readonly UnitRepository $units,
        private readonly WeeklyReportRepository $reports,
        private readonly TrooperPlacementRepository $placements,
    ) {}

    /** A `?range=` query value as weeks: 26, 52 or null for "all", 12 for anything else. */
    public static function weeksFor(?string $range): ?int
    {
        return match ($range) {
            '26' => 26,
            '52' => 52,
            'all' => null,
            default => 12,
        };
    }

    /**
     * @param  int|null  $weeks  one of {@see RANGES}
     * @return array{weeks: list<array{week: string, headcount: ?int, strength: ?int, in: ?int, out: ?int, source: ?string}>, change: array{headcount: ?int, strength: ?int}, children: list<array{id: int, designation: string, points: list<array{week: string, headcount: ?int}>, children: list<array{id: int, designation: string, points: list<array{week: string, headcount: ?int}>}>}>}
     */
    public function forUnit(Unit $unit, ?int $weeks, ?CarbonImmutable $now = null): array
    {
        $running = WeekOf::start($now ?? CarbonImmutable::now());
        $tree = $this->units->tree();
        $byId = $tree->keyBy('id');
        $lineChildrenOf = fn (Unit $parent) => $tree->filter(fn (Unit $u) => $u->parent_id === $parent->id && $u->isLine())->values();
        $children = $lineChildrenOf($unit);
        $grandchildren = $unit->type === UnitType::Battalion
            ? $children->mapWithKeys(fn (Unit $child) => [$child->id => $lineChildrenOf($child)])
            : collect();
        $shownIds = [$unit->id, ...$children->pluck('id')->all(), ...$grandchildren->flatten(1)->pluck('id')->all()];

        $earliest = $running->subWeeks(($weeks ?? self::ALL_WEEKS) - 1);
        /** @var Collection<int, WeeklyReport> $sent */
        $sent = $this->reports->sentWithin($shownIds, $earliest, $running);
        $first = $weeks !== null ? $earliest : $this->allFrom($sent, $running);

        $historyFrom = CarbonImmutable::parse(self::HISTORY_FROM, 'UTC');
        $rebuildFrom = $first->greaterThan($historyFrom) ? $first->subWeek() : $historyFrom;
        $placed = $rebuildFrom->greaterThan($running) ? [] : $this->placements->unitOfEachTrooperByWeek($rebuildFrom, $running);

        $snapshots = [];
        foreach ($sent as $report) {
            $snapshots[$report->unit_id][$report->week_start->toDateString()] = $report->kpis;
        }

        $dates = [];
        for ($week = $first; $week->lessThanOrEqualTo($running); $week = $week->addWeek()) {
            $dates[] = $week->toDateString();
        }

        $points = [];
        foreach ($dates as $date) {
            $members = isset($placed[$date]) ? $this->members($unit, $placed[$date], $byId) : null;
            $prevDate = CarbonImmutable::parse($date, 'UTC')->subWeek()->toDateString();
            $prev = isset($placed[$prevDate]) ? $this->members($unit, $placed[$prevDate], $byId) : null;
            $snapshot = $snapshots[$unit->id][$date] ?? null;

            $points[] = [
                'week' => $date,
                'headcount' => isset($snapshot['headcount']) ? (int) $snapshot['headcount'] : ($members === null ? null : count($members['all'])),
                'strength' => isset($snapshot['strength']) ? (int) $snapshot['strength'] : ($members === null ? null : count($members['sections'])),
                'in' => isset($snapshot['joined']) ? (int) $snapshot['joined'] : ($members === null || $prev === null ? null : count(array_diff_key($members['all'], $prev['all']))),
                'out' => isset($snapshot['left']) ? (int) $snapshot['left'] : ($members === null || $prev === null ? null : count(array_diff_key($prev['all'], $members['all']))),
                'source' => $snapshot !== null ? 'report' : ($members === null ? null : ($date === $running->toDateString() ? 'live' : 'history')),
            ];
        }

        return [
            'weeks' => $points,
            'change' => ['headcount' => self::change($points, 'headcount'), 'strength' => self::change($points, 'strength')],
            'children' => $children->map(fn (Unit $child) => [
                ...$this->headcountSeries($child, $dates, $snapshots, $placed, $byId),
                'children' => ($grandchildren->get($child->id) ?? collect())
                    ->map(fn (Unit $grandchild) => $this->headcountSeries($grandchild, $dates, $snapshots, $placed, $byId))
                    ->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * One unit's headcount per week for the comparison: the sent snapshot, else the rebuilt count, else a gap.
     *
     * @param  list<string>  $dates
     * @param  array<int, array<string, array<string, mixed>>>  $snapshots
     * @param  array<string, array<int, int>>  $placed
     * @param  Collection<int, Unit>  $byId
     * @return array{id: int, designation: string, points: list<array{week: string, headcount: ?int}>}
     */
    private function headcountSeries(Unit $unit, array $dates, array $snapshots, array $placed, Collection $byId): array
    {
        return [
            'id' => $unit->id,
            'designation' => $unit->callsign ?? $unit->name,
            'points' => array_map(function (string $date) use ($unit, $snapshots, $placed, $byId) {
                $snapshot = $snapshots[$unit->id][$date] ?? null;

                return [
                    'week' => $date,
                    'headcount' => isset($snapshot['headcount'])
                        ? (int) $snapshot['headcount']
                        : (isset($placed[$date]) ? count($this->members($unit, $placed[$date], $byId)['all']) : null),
                ];
            }, $dates),
        ];
    }

    /**
     * The troopers that count in `$unit` in one week, ids as keys: `all` its headcount, `sections` those in its sections.
     *
     * @param  array<int, int>  $week  trooper id => the unit they ended the week in
     * @param  Collection<int, Unit>  $byId
     * @return array{all: array<int, true>, sections: array<int, true>}
     */
    private function members(Unit $unit, array $week, Collection $byId): array
    {
        $all = [];
        $sections = [];

        foreach ($week as $trooperId => $unitId) {
            $placedIn = $byId->get($unitId);

            if ($placedIn === null || ! str_starts_with($placedIn->path, $unit->path)) {
                continue;
            }

            if (Headcount::counts($placedIn, $unit)) {
                $all[$trooperId] = true;
            }

            if ($placedIn->type === UnitType::Section && $placedIn->isLine()) {
                $sections[$trooperId] = true;
            }
        }

        return ['all' => $all, 'sections' => $sections];
    }

    /** The first week of "all": the oldest sent report, or the history's first week, whichever is earlier. */
    private function allFrom(Collection $sent, CarbonImmutable $running): CarbonImmutable
    {
        $history = CarbonImmutable::parse(self::HISTORY_FROM, 'UTC');
        $oldest = $sent->min(fn (WeeklyReport $report) => $report->week_start->toDateString());
        $from = $oldest !== null && $oldest < self::HISTORY_FROM ? CarbonImmutable::parse($oldest, 'UTC') : $history;

        return $from->greaterThan($running) ? $running : $from;
    }

    /** The last known value less the first known one over the range; null with fewer than two. */
    private static function change(array $points, string $key): ?int
    {
        $known = array_values(array_filter(array_column($points, $key), fn (?int $v) => $v !== null));

        return count($known) < 2 ? null : $known[array_key_last($known)] - $known[0];
    }
}
