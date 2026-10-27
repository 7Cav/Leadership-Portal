<?php

namespace App\Repositories\TrooperPlacementRepository;

use App\Models\PlacementSource;
use App\Models\Trooper;
use App\Models\TrooperPlacement;
use App\Models\UnitType;
use App\Repositories\PlacementWeeks;
use App\Repositories\TrooperPlacementRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class Eloquent implements TrooperPlacementRepository
{
    public function record(array $changes, PlacementSource $source, CarbonInterface $at): void
    {
        $changes = array_filter($changes, fn (array $change) => $change[0] !== $change[1]);

        if ($changes === []) {
            return;
        }

        $at = CarbonImmutable::instance($at)->utc();
        $now = CarbonImmutable::now();
        $rows = [];

        foreach (array_chunk(array_keys($changes), 500) as $ids) {
            $open = TrooperPlacement::query()->whereIn('trooper_id', $ids)->whereNull('placed_until')->pluck('trooper_id')->flip();
            TrooperPlacement::query()->whereIn('trooper_id', $ids)->whereNull('placed_until')->update(['placed_until' => $at, 'updated_at' => $now]);

            foreach ($ids as $trooperId) {
                [$from, $to] = $changes[$trooperId];

                // Placed before the history began: keep that placement for the weeks behind it.
                if ($from !== null && ! $open->has($trooperId)) {
                    $rows[] = ['trooper_id' => $trooperId, 'unit_id' => $from, 'placed_from' => null, 'placed_until' => $at, 'source' => $source->value];
                }

                if ($to !== null) {
                    $rows[] = ['trooper_id' => $trooperId, 'unit_id' => $to, 'placed_from' => $at, 'placed_until' => null, 'source' => $source->value];
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            TrooperPlacement::query()->insert(array_map(fn (array $row) => $row + ['created_at' => $now, 'updated_at' => $now], $chunk));
        }
    }

    public function historyOf(int $trooperId): Collection
    {
        return TrooperPlacement::query()
            ->with('unit')
            ->where('trooper_id', $trooperId)
            ->orderByRaw('placed_from is not null')
            ->orderBy('placed_from')
            ->orderBy('id')
            ->get();
    }

    public function sectionsByWeek(iterable|Builder $troopers, CarbonInterface $firstWeek, CarbonInterface $lastWeek): PlacementWeeks
    {
        $first = WeekOf::requireStart($firstWeek);
        $last = WeekOf::requireStart($lastWeek);

        if ($troopers instanceof Builder) {
            $ids = (clone $troopers)->select('troopers.id');
        } else {
            $ids = collect($troopers)->map(fn ($id) => (int) $id)->unique()->values()->all();

            if ($ids === []) {
                return new PlacementWeeks;
            }
        }

        // The troopers with every placement row that could reach the span, and each one's open row (which says
        // whether the history is current): only a row that ended before the span is left out.
        $rows = Trooper::query()
            ->whereIn('troopers.id', $ids)
            ->whereNotNull('troopers.primary_section_id')
            ->leftJoin('trooper_placements', function ($join) use ($first) {
                $join->on('trooper_placements.trooper_id', '=', 'troopers.id')
                    ->where(fn ($q) => $q->whereNull('trooper_placements.placed_until')->orWhere('trooper_placements.placed_until', '>', $first));
            })
            ->leftJoin('units', 'units.id', '=', 'trooper_placements.unit_id')
            ->orderBy('troopers.id')
            ->orderByRaw('trooper_placements.placed_from is not null')
            ->orderBy('trooper_placements.placed_from')
            ->orderBy('trooper_placements.id')
            ->toBase()
            ->get([
                'troopers.id as trooper_id',
                'troopers.primary_section_id as current_unit',
                'trooper_placements.id as placement_id',
                'trooper_placements.unit_id',
                'trooper_placements.placed_from',
                'trooper_placements.placed_until',
                'units.type as unit_type',
            ])
            ->groupBy('trooper_id');

        $weeks = [];

        for ($week = $first; $week->lessThanOrEqualTo($last); $week = $week->addWeek()) {
            $weeks[] = $week;
        }

        $current = [];
        $byWeek = [];

        foreach ($rows as $trooperId => $history) {
            $trooperId = (int) $trooperId;
            $currentUnit = (int) $history->first()->current_unit;
            $current[$trooperId] = $currentUnit;
            $history = $history->filter(fn (object $row) => $row->placement_id !== null);
            $open = $history->first(fn (object $row) => $row->placed_until === null);

            if ($open === null || (int) $open->unit_id !== $currentUnit) {
                foreach ($weeks as $week) {
                    $byWeek[$trooperId][$week->toDateString()] = [$currentUnit];
                }

                continue;
            }

            $sections = $history
                ->filter(fn (object $row) => $row->unit_type === UnitType::Section->value)
                ->map(fn (object $row) => [
                    'unit' => (int) $row->unit_id,
                    'from' => $row->placed_from === null ? null : CarbonImmutable::parse($row->placed_from, 'UTC'),
                    'until' => $row->placed_until === null ? null : CarbonImmutable::parse($row->placed_until, 'UTC'),
                ]);

            foreach ($weeks as $week) {
                $end = $week->addWeek();
                $during = $sections
                    ->filter(fn (array $row) => ($row['from'] === null || $row['from']->lessThan($end))
                        && ($row['until'] === null || $row['until']->greaterThan($week)))
                    ->pluck('unit')
                    ->unique()
                    ->values()
                    ->all();

                if ($during !== []) {
                    $byWeek[$trooperId][$week->toDateString()] = $during;
                }
            }
        }

        return new PlacementWeeks($current, $byWeek);
    }
}
