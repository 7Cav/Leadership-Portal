<?php

namespace App\Repositories\TrooperPlacementRepository;

use App\Models\PlacementSource;
use App\Models\Trooper;
use App\Models\TrooperPlacement;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\PlacementWeeks;
use App\Repositories\TrooperPlacementRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
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

    public function unitIdsOfTrooperInWeek(Trooper $trooper, CarbonInterface $weekStart): array
    {
        $week = WeekOf::requireStart($weekStart);
        $end = $week->addWeek();
        $current = $trooper->primary_section_id;
        $rows = TrooperPlacement::query()
            ->where('trooper_id', $trooper->id)
            ->orderByRaw('placed_from is not null')
            ->orderBy('placed_from')
            ->orderBy('id')
            ->get(['unit_id', 'placed_from', 'placed_until']);
        $open = $rows->first(fn (TrooperPlacement $row) => $row->placed_until === null);

        if ($open === null || $current === null || (int) $open->unit_id !== $current) {
            return $current === null ? [] : [$current];
        }

        return $rows
            ->filter(fn (TrooperPlacement $row) => $row->unit_id !== null
                && ($row->placed_from === null || CarbonImmutable::instance($row->placed_from)->lessThan($end))
                && ($row->placed_until === null || CarbonImmutable::instance($row->placed_until)->greaterThan($week)))
            ->pluck('unit_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function unitOfTrooperAtWeekEnd(Trooper $trooper, CarbonInterface $weekStart): ?int
    {
        return $this->unitsAtWeekEnd(collect([$trooper]), $weekStart)[$trooper->id] ?? null;
    }

    public function unitsOfTroopersAtWeekEnd(Collection $troopers, CarbonInterface $weekStart): array
    {
        return $this->unitsAtWeekEnd($troopers->unique('id')->values(), $weekStart);
    }

    public function trooperIdsInUnitAtWeekEnd(int $unitId, CarbonInterface $weekStart): array
    {
        // Everyone who was ever placed there or is placed there now; the history then says where each stood at the end.
        $candidates = Trooper::query()
            ->where('primary_section_id', $unitId)
            ->orWhereIn('id', TrooperPlacement::query()->select('trooper_id')->where('unit_id', $unitId))
            ->orderBy('id')
            ->get(['id', 'primary_section_id']);

        return collect($this->unitsAtWeekEnd($candidates, $weekStart))
            ->filter(fn (?int $unit) => $unit === $unitId)
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Where each trooper stood at the end of the week: the placement row that holds the last instant of the week. A
     * trooper with no history, whose open row is not their current unit, or whose history only began after the week
     * ended, is read as placed in their current unit, as {@see unitIdsOfTrooperInWeek()} reads them.
     *
     * @param  Collection<int, Trooper>  $troopers
     * @return array<int, ?int> trooper id => unit id
     */
    private function unitsAtWeekEnd(Collection $troopers, CarbonInterface $weekStart): array
    {
        $end = WeekOf::requireStart($weekStart)->addWeek();
        $rows = $troopers->isEmpty() ? collect() : TrooperPlacement::query()
            ->whereIn('trooper_id', $troopers->pluck('id')->all())
            ->get(['trooper_id', 'unit_id', 'placed_from', 'placed_until'])
            ->groupBy('trooper_id');
        $units = [];

        foreach ($troopers as $trooper) {
            $own = $rows->get($trooper->id, collect());
            $current = $trooper->primary_section_id === null ? null : (int) $trooper->primary_section_id;
            $open = $own->first(fn (TrooperPlacement $row) => $row->placed_until === null);

            if ($open === null || $current === null || (int) $open->unit_id !== $current) {
                $units[$trooper->id] = $current;

                continue;
            }

            // The history began after the week ended (every row starts at or after its end): it says nothing about
            // that week, so they are read as placed in their current unit, as the roll call reads a week with no
            // history.
            if ($own->every(fn (TrooperPlacement $row) => $row->placed_from !== null && CarbonImmutable::instance($row->placed_from)->greaterThanOrEqualTo($end))) {
                $units[$trooper->id] = $current;

                continue;
            }

            // The row that started before the week ended and had not ended by then (still open, or closed at or after
            // the end). A trooper whose history shows a gap at the end of the week was nowhere then.
            $holding = $own->first(fn (TrooperPlacement $row) => ($row->placed_from === null || CarbonImmutable::instance($row->placed_from)->lessThan($end))
                && ($row->placed_until === null || CarbonImmutable::instance($row->placed_until)->greaterThanOrEqualTo($end)));
            $units[$trooper->id] = $holding?->unit_id === null ? null : (int) $holding->unit_id;
        }

        return $units;
    }

    public function leftUnitBetween(int $unitId, array $trooperIds, CarbonInterface $from, CarbonInterface $until): array
    {
        if ($trooperIds === []) {
            return [];
        }

        $from = CarbonImmutable::instance($from)->utc();
        $until = CarbonImmutable::instance($until)->utc();

        // Each one's last row in the unit decides: open (still there) or closed at or after `$until` (back by then, or
        // left later) is not a move out in the span, however often they left and came back inside it.
        return TrooperPlacement::query()
            ->where('unit_id', $unitId)
            ->whereIn('trooper_id', $trooperIds)
            ->get(['trooper_id', 'placed_until'])
            ->groupBy('trooper_id')
            ->filter(function ($rows) use ($from, $until) {
                if ($rows->contains(fn (TrooperPlacement $row) => $row->placed_until === null)) {
                    return false;
                }

                $last = CarbonImmutable::instance($rows->max('placed_until'))->utc();

                return $last->greaterThanOrEqualTo($from) && $last->lessThan($until);
            })
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    public function unitIdsPlacedByWeek(CarbonInterface $firstWeek, CarbonInterface $lastWeek): array
    {
        $first = WeekOf::requireStart($firstWeek);
        $last = WeekOf::requireStart($lastWeek);
        $weeks = self::weeks($first, $last);
        $byWeek = array_fill_keys(array_map(fn (CarbonImmutable $week) => $week->toDateString(), $weeks), []);

        // Everyone who served in the span (serving now, or gone only after it opened), read as a past week's roster
        // reads them (the 2026-10-29 audit), so the report chain and the rollcall agree on who was in which section.
        // A trooper placed nowhere now with no open row (moved out of every unit) is read through their closed rows.
        $served = Trooper::query()
            ->select('troopers.id')
            ->where(fn ($q) => $q->whereIn('troopers.status', TrooperStatus::servingValues())->orWhere('troopers.departed_at', '>', $first));

        foreach ($this->rowsOf($served, $first, null, unplaced: true) as $history) {
            $head = $history->first();
            // A trooper who left counts for the weeks that opened before they went.
            $gone = TrooperStatus::from($head->status)->isServing() ? null : CarbonImmutable::parse($head->departed_at, 'UTC');
            $placed = self::placedWeeks($history, $head->current_unit === null ? null : (int) $head->current_unit, $weeks);

            foreach ($placed['sections'] as $date => $sections) {
                if ($gone === null || $gone->greaterThan(CarbonImmutable::parse($date, 'UTC'))) {
                    // The section they ended the week in, the one whose roster lists them (PlacementWeeks::sectionIn).
                    $byWeek[$date][$sections[array_key_last($sections)]] = true;
                }
            }

            foreach ($placed['others'] as $date => $units) {
                if ($gone === null || $gone->greaterThan(CarbonImmutable::parse($date, 'UTC'))) {
                    $byWeek[$date] += array_fill_keys($units, true);
                }
            }
        }

        return array_map(function (array $units) {
            ksort($units);

            return array_keys($units);
        }, $byWeek);
    }

    public function sectionsByWeek(iterable|Builder $troopers, CarbonInterface $firstWeek, CarbonInterface $lastWeek): PlacementWeeks
    {
        return $this->read($troopers, WeekOf::requireStart($firstWeek), WeekOf::requireStart($lastWeek), null);
    }

    public function sectionsByWeekWithin(Unit $unit, CarbonInterface $firstWeek, CarbonInterface $lastWeek): PlacementWeeks
    {
        return $this->endedIn(
            Unit::query()->select('id')->where('path', 'like', "{$unit->path}%"),
            WeekOf::requireStart($firstWeek),
            WeekOf::requireStart($lastWeek),
            fn (string $path, int $sectionId) => str_starts_with($path, $unit->path),
        );
    }

    public function sectionsByWeekAmong(array $sectionIds, CarbonInterface $firstWeek, CarbonInterface $lastWeek): PlacementWeeks
    {
        $first = WeekOf::requireStart($firstWeek);
        $last = WeekOf::requireStart($lastWeek);
        $ids = array_values(array_unique(array_map('intval', $sectionIds)));

        if ($ids === []) {
            return new PlacementWeeks;
        }

        $among = array_flip($ids);

        return $this->endedIn($ids, $first, $last, fn (string $path, int $sectionId) => isset($among[$sectionId]));
    }

    /**
     * The troopers who served the week `$last` and ended it in a section among `$units` (a query of unit ids, or the
     * ids), with where they were placed over the span: {@see sectionsByWeekWithin()} and {@see sectionsByWeekAmong()}.
     *
     * @param  Builder<Unit>|list<int>  $units
     * @param  Closure(string, int): bool  $keep  whether the section (its path and id) a trooper ended the week in counts
     */
    private function endedIn(Builder|array $units, CarbonImmutable $first, CarbonImmutable $last, Closure $keep): PlacementWeeks
    {
        $end = $last->addWeek();

        // Everyone who may count that week, as one subquery: serving then (serving now, or gone only after the week
        // opened), and placed in one of the units now or by a placement row that overlaps the week. Where each one
        // ended the week is decided by read(), from the same rows sectionsByWeek() reads.
        $candidates = Trooper::query()
            ->select('troopers.id')
            ->where(fn ($q) => $q->whereIn('troopers.status', TrooperStatus::servingValues())->orWhere('troopers.departed_at', '>', $last))
            ->where(fn ($q) => $q->whereIn('troopers.primary_section_id', $units)
                ->orWhereExists(fn ($q) => $q->selectRaw('1')
                    ->from('trooper_placements as during')
                    ->whereColumn('during.trooper_id', 'troopers.id')
                    ->whereIn('during.unit_id', $units)
                    ->where(fn ($q) => $q->whereNull('during.placed_from')->orWhere('during.placed_from', '<', $end))
                    ->where(fn ($q) => $q->whereNull('during.placed_until')->orWhere('during.placed_until', '>', $last))));

        return $this->read($candidates, $first, $last, $keep);
    }

    /**
     * {@see sectionsByWeek()}, kept to the troopers whose section at the end of `$last` passes `$keep` when one is
     * given ({@see endedIn()}).
     *
     * @param  iterable<int>|Builder<Trooper>  $troopers
     * @param  (Closure(string, int): bool)|null  $keep
     */
    private function read(iterable|Builder $troopers, CarbonImmutable $first, CarbonImmutable $last, ?Closure $keep): PlacementWeeks
    {
        if ($troopers instanceof Builder) {
            $ids = (clone $troopers)->select('troopers.id');
        } else {
            $ids = collect($troopers)->map(fn ($id) => (int) $id)->unique()->values()->all();

            if ($ids === []) {
                return new PlacementWeeks;
            }
        }

        $rows = $this->rowsOf($ids, $first, $keep);
        $weeks = self::weeks($first, $last);
        $current = [];
        $byWeek = [];

        foreach ($rows as $trooperId => $history) {
            $trooperId = (int) $trooperId;
            $current[$trooperId] = (int) $history->first()->current_unit;
            $sections = self::placedWeeks($history, $current[$trooperId], $weeks)['sections'];

            if ($sections !== []) {
                $byWeek[$trooperId] = $sections;
            }
        }

        if ($keep === null) {
            return new PlacementWeeks($current, $byWeek);
        }

        // The path of every section met, so a trooper counts only in a section, as `assignedWithin` does: the current
        // unit of someone read without history may be an HQ node or a group.
        $paths = [];

        foreach ($rows->flatten(1) as $row) {
            if ($row->current_type === UnitType::Section->value) {
                $paths[(int) $row->current_unit] = $row->current_path;
            }

            if ($row->unit_id !== null && $row->unit_type === UnitType::Section->value) {
                $paths[(int) $row->unit_id] = $row->unit_path;
            }
        }

        $week = $last->toDateString();
        $kept = array_filter($byWeek, function (array $weeks) use ($week, $paths, $keep) {
            $section = isset($weeks[$week]) ? end($weeks[$week]) : null;

            return $section !== null && isset($paths[$section]) && $keep($paths[$section], $section);
        });

        return new PlacementWeeks(array_intersect_key($current, $kept), $kept);
    }

    /**
     * The troopers with every placement row that could reach a span opening at `$first`, and each one's open row
     * (which says whether the history is current): only a row that ended before the span is left out. Grouped by
     * trooper, each one's rows oldest first. Troopers placed now only, unless `$unplaced`; with `$keep`, the units'
     * paths too.
     *
     * @param  list<int>|Builder<Trooper>  $ids
     * @return Collection<int|string, Collection<int, object>>
     */
    private function rowsOf(array|Builder $ids, CarbonImmutable $first, ?Closure $keep, bool $unplaced = false): Collection
    {
        return Trooper::query()
            ->whereIn('troopers.id', $ids)
            ->when(! $unplaced, fn ($q) => $q->whereNotNull('troopers.primary_section_id'))
            ->leftJoin('trooper_placements', function ($join) use ($first) {
                $join->on('trooper_placements.trooper_id', '=', 'troopers.id')
                    ->where(fn ($q) => $q->whereNull('trooper_placements.placed_until')->orWhere('trooper_placements.placed_until', '>', $first));
            })
            ->leftJoin('units', 'units.id', '=', 'trooper_placements.unit_id')
            // The units' paths, so read() can tell which troopers ended the week where `$keep` asks.
            ->when($keep !== null, fn ($q) => $q->leftJoin('units as current_units', 'current_units.id', '=', 'troopers.primary_section_id'))
            ->orderBy('troopers.id')
            ->orderByRaw('trooper_placements.placed_from is not null')
            ->orderBy('trooper_placements.placed_from')
            ->orderBy('trooper_placements.id')
            ->toBase()
            ->get([
                'troopers.id as trooper_id',
                'troopers.primary_section_id as current_unit',
                'troopers.status',
                'troopers.departed_at',
                'trooper_placements.id as placement_id',
                'trooper_placements.unit_id',
                'trooper_placements.placed_from',
                'trooper_placements.placed_until',
                'units.type as unit_type',
                ...($keep === null ? [] : ['units.path as unit_path', 'current_units.path as current_path', 'current_units.type as current_type']),
            ])
            ->groupBy('trooper_id');
    }

    /**
     * Where one trooper was placed in each of `$weeks`, from their rows ({@see rowsOf()}): `sections` holds, by week
     * start (Y-m-d), the sections they were in that week in the order they were placed there, so the last is where
     * they ended it; `others` the other units (HQ staff, a group) they were in at any moment of it. A week they were
     * in neither is absent.
     *
     * A trooper with no history, or whose open row is not their current unit (a placement written around this
     * repository, such as a seeder), is read as placed in their current unit every week, whatever it is, under
     * `sections`: the rule from before the history existed. One placed nowhere is read by their rows when they have
     * no open row (moved out of every unit), and as placed nowhere when they have one (its unit deleted).
     *
     * @param  Collection<int, object>  $history
     * @param  list<CarbonImmutable>  $weeks
     * @return array{sections: array<string, list<int>>, others: array<string, list<int>>}
     */
    private static function placedWeeks(Collection $history, ?int $currentUnit, array $weeks): array
    {
        $history = $history->filter(fn (object $row) => $row->placement_id !== null);
        $open = $history->first(fn (object $row) => $row->placed_until === null);
        $sections = [];
        $others = [];
        $untracked = $currentUnit === null ? $open !== null : $open === null || (int) $open->unit_id !== $currentUnit;

        if ($untracked) {
            foreach ($currentUnit === null ? [] : $weeks as $week) {
                $sections[$week->toDateString()] = [$currentUnit];
            }

            return ['sections' => $sections, 'others' => $others];
        }

        $rows = $history
            ->filter(fn (object $row) => $row->unit_id !== null)
            ->map(fn (object $row) => [
                'unit' => (int) $row->unit_id,
                'section' => $row->unit_type === UnitType::Section->value,
                'from' => $row->placed_from === null ? null : CarbonImmutable::parse($row->placed_from, 'UTC'),
                'until' => $row->placed_until === null ? null : CarbonImmutable::parse($row->placed_until, 'UTC'),
            ]);

        foreach ($weeks as $week) {
            $end = $week->addWeek();
            $during = $rows->filter(fn (array $row) => ($row['from'] === null || $row['from']->lessThan($end))
                && ($row['until'] === null || $row['until']->greaterThan($week)));
            $inSections = $during
                ->where('section', true)
                ->pluck('unit')
                // A section met twice (A, B, back to A) keeps its last place, so "placed last" is where they ended.
                ->reverse()
                ->unique()
                ->reverse()
                ->values()
                ->all();
            $elsewhere = $during->where('section', false)->pluck('unit')->unique()->values()->all();

            if ($inSections !== []) {
                $sections[$week->toDateString()] = $inSections;
            }

            if ($elsewhere !== []) {
                $others[$week->toDateString()] = $elsewhere;
            }
        }

        return ['sections' => $sections, 'others' => $others];
    }

    /**
     * Every Cav week from `$first` to `$last`.
     *
     * @return list<CarbonImmutable>
     */
    private static function weeks(CarbonImmutable $first, CarbonImmutable $last): array
    {
        $weeks = [];

        for ($week = $first; $week->lessThanOrEqualTo($last); $week = $week->addWeek()) {
            $weeks[] = $week;
        }

        return $weeks;
    }
}
