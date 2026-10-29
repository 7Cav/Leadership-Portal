<?php

namespace App\Repositories\TrooperRepository;

use App\Models\PlacementSource;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\SectionWeekRoster;
use App\Repositories\TrooperPlacementRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\WeekRollcalls;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Eloquent implements TrooperRepository
{
    public function __construct(
        private readonly TrooperPlacementRepository $placements,
    ) {}

    public function sync(array $rows, array $activeCavIds, array $unreadableCavIds = [], ?array $withinSectionIds = null): array
    {
        return DB::transaction(function () use ($rows, $activeCavIds, $unreadableCavIds, $withinSectionIds) {
            $upserted = $this->upsertActive($rows);
            $inactivated = $this->markDepartedExcept($activeCavIds, $withinSectionIds);

            if ($unreadableCavIds !== []) {
                Trooper::query()->whereIn('cav_id', $unreadableCavIds)->update(['cav_position_id' => null, 'billet' => null]);
            }

            return [...$upserted, 'inactivated' => $inactivated];
        });
    }

    public function upsertActive(array $rows): array
    {
        $inserted = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $trooper = Trooper::updateOrCreate(
                ['cav_id' => $row['cav_id']],
                [
                    'rank' => $row['rank'],
                    'rank_short' => $row['rank_short'] ?? null,
                    'rank_image_url' => $row['rank_image_url'] ?? null,
                    'username' => $row['username'] ?? null,
                    'forum_user_id' => $row['forum_user_id'] ?? null,
                    'billet' => $row['billet'] ?? null,
                    'mos' => $row['mos'],
                    'tis' => $row['tis'],
                    'tig' => $row['tig'],
                    'status' => $row['status'],
                    'cav_position_id' => $row['cav_position_id'] ?? null,
                ],
            );

            // When they left every serving roster, for a past week's rollcall (2026-10-29): set as the status leaves
            // the serving ones, cleared when it returns. A trooper first seen already departed has no known date.
            if (! $trooper->wasRecentlyCreated && $trooper->wasChanged('status')) {
                $trooper->forceFill(['departed_at' => $trooper->status->isServing() ? null : ($trooper->departed_at ?? CarbonImmutable::now())])->save();
            }

            if ($trooper->wasRecentlyCreated) {
                $inserted++;
            } elseif ($trooper->wasChanged()) {
                // An unchanged row is not saved at all, so a sync that changes nothing reports no updates.
                $updated++;
            }
        }

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    public function markDepartedExcept(array $activeCavIds, ?array $withinSectionIds = null): int
    {
        return Trooper::query()
            ->when($withinSectionIds !== null, fn (Builder $query) => $query->whereIn('primary_section_id', $withinSectionIds))
            ->whereNotIn('cav_id', $activeCavIds)
            // Not a serving check: "not already departed", so a trooper holding any other standing who
            // has left every roster is marked departed too, and the count only reports new departures.
            ->where('status', '!=', TrooperStatus::Inactive->value)
            ->update(['status' => TrooperStatus::Inactive->value, 'departed_at' => CarbonImmutable::now()]);
    }

    public function countDepartingWithStatus(TrooperStatus $status, array $activeCavIds): int
    {
        return Trooper::query()
            ->where('status', $status->value)
            ->whereNotIn('cav_id', $activeCavIds)
            ->count();
    }

    public function updatePrimarySections(array $cavIdToSectionId, array $eloaUnitIds = []): int
    {
        return DB::transaction(fn () => $this->applyPrimarySections($cavIdToSectionId, $eloaUnitIds));
    }

    /**
     * @param  array<int, int>  $cavIdToSectionId
     * @param  list<int>  $eloaUnitIds
     */
    private function applyPrimarySections(array $cavIdToSectionId, array $eloaUnitIds): int
    {
        $updated = 0;
        $at = CarbonImmutable::now();
        $lineUnits = Unit::query()->select('id')->where('kind', UnitKind::Line->value);

        // Grouped by target unit rather than one UPDATE per trooper: a full-roster sync can
        // resolve several hundred troopers into a handful of units, and this turns that into
        // one query per unit instead of one per trooper.
        foreach (collect($cavIdToSectionId)->groupBy(fn ($sectionId) => $sectionId, preserveKeys: true) as $sectionId => $cavIds) {
            // The ELOA "was": moving into ELOA from a line unit keeps that unit; moving in from anywhere else (a group,
            // unplaced, already on ELOA) keeps whatever was recorded; any other placement clears it. Listed before
            // `primary_section_id` on purpose: MySQL assigns left to right and reads the new value in later
            // assignments, so the old placement must be read first.
            $was = in_array((int) $sectionId, array_map('intval', $eloaUnitIds), true)
                ? DB::raw('CASE WHEN primary_section_id IN ('.$lineUnits->toRawSql().') THEN primary_section_id ELSE last_line_unit_id END')
                : null;

            // Who actually moves, read before the write, for the placement history (2026-10-27).
            $moving = Trooper::query()
                ->whereIn('cav_id', $cavIds->keys())
                ->where(fn (Builder $query) => $query->whereNull('primary_section_id')->orWhere('primary_section_id', '!=', $sectionId))
                ->pluck('primary_section_id', 'id');

            $updated += Trooper::query()
                ->whereIn('cav_id', $cavIds->keys())
                ->update([
                    'last_line_unit_id' => $was,
                    'primary_section_id' => $sectionId,
                    'placement_source' => PlacementSource::Sync->value,
                ]);

            $this->placements->record(
                $moving->map(fn (?int $from) => [$from, (int) $sectionId])->all(),
                PlacementSource::Sync,
                $at,
            );
        }

        return $updated;
    }

    public function countsBySection(): Collection
    {
        return Trooper::query()
            ->whereNotNull('primary_section_id')
            ->whereIn('status', TrooperStatus::servingValues())
            ->groupBy('primary_section_id')
            ->selectRaw('primary_section_id, count(*) as total')
            ->pluck('total', 'primary_section_id')
            ->map(fn ($total) => (int) $total);
    }

    public function place(int $trooperId, int $sectionId): ?Trooper
    {
        // One conditional UPDATE rather than read-then-write: it only lands while the trooper is
        // still unplaced and still on a roster, so two Admins placing the same trooper at once
        // cannot both win, and neither can overwrite a placement the sync just made.
        return DB::transaction(function () use ($trooperId, $sectionId) {
            $placed = Trooper::query()
                ->whereKey($trooperId)
                ->whereNull('primary_section_id')
                ->whereIn('status', TrooperStatus::servingValues())
                ->update(['primary_section_id' => $sectionId, 'placement_source' => PlacementSource::Manual->value]);

            if ($placed !== 1) {
                return null;
            }

            $this->placements->record([$trooperId => [null, $sectionId]], PlacementSource::Manual, CarbonImmutable::now());

            return Trooper::find($trooperId);
        });
    }

    public function idsPlacedIn(int $sectionId, array $trooperIds): Collection
    {
        return Trooper::query()
            ->where('primary_section_id', $sectionId)
            ->whereIn('id', $trooperIds)
            ->orderBy('id')
            ->pluck('id');
    }

    public function servingIdsPlacedIn(int $sectionId, array $trooperIds): Collection
    {
        return Trooper::query()
            ->where('primary_section_id', $sectionId)
            ->whereIn('id', $trooperIds)
            ->whereIn('status', TrooperStatus::servingValues())
            ->orderBy('id')
            ->pluck('id');
    }

    public function onRollcall(Unit $section, CarbonInterface $weekStart): SectionWeekRoster
    {
        $weekStart = WeekOf::requireStart($weekStart);

        if (! $this->hasEnded($weekStart)) {
            return new SectionWeekRoster($this->placedIn($section->id));
        }

        $placements = $this->placements->sectionsByWeekAmong([$section->id], $weekStart, $weekStart);
        $ids = array_keys($placements->current);
        $week = $weekStart->toDateString();

        return new SectionWeekRoster(
            $ids === [] ? new EloquentCollection : Trooper::query()->whereIn('id', $ids)->orderBy('id')->get(),
            asPlaced: true,
            // Placed somewhere else first that week, then here.
            joined: array_values(array_filter($ids, fn (int $id) => count($placements->weeks[$id][$week] ?? []) > 1)),
        );
    }

    public function idsOnRollcall(int $sectionId, CarbonInterface $weekStart, array $trooperIds, bool $serving = false): Collection
    {
        $weekStart = WeekOf::requireStart($weekStart);

        if (! $this->hasEnded($weekStart)) {
            return $serving ? $this->servingIdsPlacedIn($sectionId, $trooperIds) : $this->idsPlacedIn($sectionId, $trooperIds);
        }

        if ($trooperIds === []) {
            return collect();
        }

        $on = array_keys($this->placements->sectionsByWeekAmong([$sectionId], $weekStart, $weekStart)->current);

        return collect(array_values(array_intersect($on, array_map('intval', $trooperIds))))->sort()->values();
    }

    public function onRollcalls(array $sectionIds, CarbonInterface $weekStart): WeekRollcalls
    {
        $weekStart = WeekOf::requireStart($weekStart);

        if ($sectionIds === []) {
            return new WeekRollcalls([], $this->hasEnded($weekStart));
        }

        if (! $this->hasEnded($weekStart)) {
            return new WeekRollcalls(Trooper::query()
                ->whereIn('primary_section_id', $sectionIds)
                ->whereIn('status', TrooperStatus::servingValues())
                ->orderBy('id')
                ->pluck('primary_section_id', 'id')
                ->map(fn ($sectionId) => (int) $sectionId)
                ->all());
        }

        $placements = $this->placements->sectionsByWeekAmong($sectionIds, $weekStart, $weekStart);
        $week = $weekStart->toDateString();
        $sections = [];

        foreach (array_keys($placements->current) as $trooperId) {
            $sections[$trooperId] = (int) $placements->sectionIn($trooperId, $week);
        }

        return new WeekRollcalls($sections, asPlaced: true);
    }

    public function ownSectionsInWeek(array $trooperIds, CarbonInterface $weekStart): array
    {
        $weekStart = WeekOf::requireStart($weekStart);
        $ids = array_values(array_unique(array_map('intval', $trooperIds)));

        if ($ids === []) {
            return [];
        }

        if (! $this->hasEnded($weekStart)) {
            return Trooper::query()
                ->whereIn('id', $ids)
                ->whereNotNull('primary_section_id')
                ->orderBy('id')
                ->pluck('primary_section_id', 'id')
                ->map(fn ($unitId) => (int) $unitId)
                ->all();
        }

        $week = $weekStart->toDateString();
        $placements = $this->placements->sectionsByWeek($ids, $weekStart, $weekStart);
        $sections = [];

        foreach ($ids as $id) {
            $section = $placements->sectionIn($id, $week);

            if ($section !== null) {
                $sections[$id] = $section;
            }
        }

        if ($sections === []) {
            return [];
        }

        // Kept only where that rollcall really lists them, read as onRollcall() reads it: a section unit, and serving
        // that week.
        $listed = $this->placements->sectionsByWeekAmong(array_values(array_unique($sections)), $weekStart, $weekStart)->current;
        $sections = array_intersect_key($sections, $listed);
        ksort($sections);

        return $sections;
    }

    /** A week before the running one: its roster is the one it ended with (the 2026-10-29 audit). */
    private function hasEnded(CarbonImmutable $weekStart): bool
    {
        return $weekStart->lessThan(WeekOf::start(CarbonImmutable::now()));
    }

    public function assignedWithin(Unit $unit): Collection
    {
        return Trooper::query()->assignedWithin($unit)->orderBy('id')->pluck('id');
    }

    public function unplaced(int $limit): Collection
    {
        return $this->unplacedQuery()
            ->orderByRaw('username is null')
            ->orderBy('username')
            ->orderBy('cav_id')
            ->limit($limit)
            ->get()
            ->map(fn (Trooper $trooper) => [
                'id' => $trooper->id,
                'cav_id' => $trooper->cav_id,
                'username' => $trooper->username,
                'rank' => $trooper->rank,
                'mos' => $trooper->mos,
                'billet' => $trooper->billet,
                'position_id' => $trooper->cav_position_id,
            ])
            ->toBase();
    }

    public function unplacedCount(): int
    {
        return $this->unplacedQuery()->count();
    }

    /**
     * @return Builder<Trooper>
     */
    private function unplacedQuery(): Builder
    {
        return Trooper::query()
            ->whereNull('primary_section_id')
            ->whereIn('status', TrooperStatus::servingValues());
    }

    public function byCavId(array $cavIds): Collection
    {
        return $cavIds === [] ? collect() : Trooper::query()->whereIn('cav_id', $cavIds)->get()->keyBy('cav_id')->toBase();
    }

    public function ranksByCavId(array $cavIds): Collection
    {
        return Trooper::query()->whereIn('cav_id', $cavIds)->whereNotNull('rank')->pluck('rank', 'cav_id');
    }

    public function statusOf(int $cavId): ?TrooperStatus
    {
        return Trooper::query()->where('cav_id', $cavId)->first(['status'])?->status;
    }

    public function servingCavIds(array $cavIds): Collection
    {
        if ($cavIds === []) {
            return collect();
        }

        return Trooper::query()
            ->whereIn('cav_id', $cavIds)
            ->whereIn('status', TrooperStatus::servingValues())
            ->orderBy('cav_id')
            ->pluck('cav_id')
            ->map(fn ($cavId) => (int) $cavId);
    }

    public function idsByUsername(array $usernames): array
    {
        $wanted = array_values(array_unique(array_map(fn (string $name) => mb_strtolower($name), $usernames)));

        if ($wanted === []) {
            return [];
        }

        $found = [];

        foreach (Trooper::query()->whereIn(DB::raw('lower(username)'), $wanted)->orderBy('id')->get(['id', 'username']) as $trooper) {
            $found[mb_strtolower($trooper->username)][] = $trooper->id;
        }

        return $found;
    }

    public function idsByForumUserId(array $forumUserIds): array
    {
        $wanted = array_values(array_unique($forumUserIds));

        if ($wanted === []) {
            return [];
        }

        $found = [];

        foreach (Trooper::query()->whereIn('forum_user_id', $wanted)->orderBy('id')->get(['id', 'forum_user_id']) as $trooper) {
            $found[(int) $trooper->forum_user_id][] = $trooper->id;
        }

        return $found;
    }

    public function placedWithin(Unit $unit): EloquentCollection
    {
        return Trooper::query()
            ->with('lastLineUnit')
            ->whereIn('primary_section_id', Unit::query()->select('id')->where('path', 'like', "{$unit->path}%"))
            ->orderBy('id')
            ->get();
    }

    public function activePlacedWithin(Unit $unit): EloquentCollection
    {
        return Trooper::query()->placedWithin($unit)->orderBy('id')->get();
    }

    public function servingPlacedIn(iterable $unitIds): EloquentCollection
    {
        $ids = collect($unitIds)->values()->all();

        return $ids === [] ? new EloquentCollection : Trooper::query()
            ->with('lastLineUnit')
            ->whereIn('primary_section_id', $ids)
            ->whereIn('status', TrooperStatus::servingValues())
            ->orderByRaw('username is null')
            ->orderBy('username')
            ->orderBy('id')
            ->get();
    }

    public function find(int $id): ?Trooper
    {
        return Trooper::query()->find($id);
    }

    public function servingHoldingPositions(array $positionIds): Collection
    {
        if ($positionIds === []) {
            return collect();
        }

        return Trooper::query()
            ->whereIn('cav_position_id', $positionIds)
            ->whereIn('status', TrooperStatus::servingValues())
            ->orderBy('username')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Trooper $trooper) => (int) $trooper->cav_position_id)
            ->map(fn ($troopers) => $troopers->toBase()->values())
            ->toBase();
    }

    public function servingWithoutAccount(): EloquentCollection
    {
        return Trooper::query()
            ->whereIn('status', TrooperStatus::servingValues())
            ->whereNotNull('cav_id')
            ->whereNotIn('cav_id', User::query()->select('cav_id')->whereNotNull('cav_id'))
            ->orderBy('username')
            ->get();
    }

    public function searchByUsername(array $terms, ?int $cavId, iterable $sectionIds, bool $withUnplaced, int $limit, ?int $ownCavId = null): EloquentCollection
    {
        // `!` escapes the LIKE wildcards: one escape character both SQLite and MySQL accept without quoting games.
        $escaped = array_map(fn (string $term) => strtr(mb_strtolower($term), ['!' => '!!', '%' => '!%', '_' => '!_']), array_values($terms));

        if ($escaped === [] && $cavId === null) {
            return new EloquentCollection;
        }

        return Trooper::query()
            ->where(fn (Builder $scope) => $scope
                ->whereIn('primary_section_id', collect($sectionIds)->all())
                ->when($withUnplaced, fn (Builder $q) => $q->orWhereNull('primary_section_id'))
                ->when($ownCavId !== null, fn (Builder $q) => $q->orWhere('cav_id', $ownCavId)))
            ->where(function (Builder $match) use ($escaped, $cavId) {
                foreach ($escaped as $term) {
                    $match->orWhereRaw("LOWER(username) LIKE ? ESCAPE '!'", ["%{$term}%"]);
                }
                if ($cavId !== null) {
                    $match->orWhere('cav_id', $cavId);
                }
            })
            ->orderByRaw('CASE WHEN status IN ('.implode(', ', array_fill(0, count(TrooperStatus::serving()), '?')).') THEN 0 ELSE 1 END', TrooperStatus::servingValues())
            ->when($cavId !== null, fn (Builder $q) => $q->orderByRaw('CASE WHEN cav_id = ? THEN 0 ELSE 1 END', [$cavId]))
            ->when($escaped !== [], fn (Builder $q) => $q->orderByRaw("CASE WHEN LOWER(username) LIKE ? ESCAPE '!' THEN 0 ELSE 1 END", ["{$escaped[0]}%"]))
            ->orderBy('username')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function searchGuests(string $term, int $hostSectionId, int $limit): EloquentCollection
    {
        $escaped = strtr(mb_strtolower($term), ['!' => '!!', '%' => '!%', '_' => '!_']);

        return Trooper::query()
            // Placed in a section: HQ staff and groups have no rollcall to carry the makeup.
            ->whereIn('primary_section_id', Unit::query()->select('id')->where('type', UnitType::Section->value))
            ->where('primary_section_id', '!=', $hostSectionId)
            ->whereIn('status', TrooperStatus::servingValues())
            ->whereRaw("LOWER(username) LIKE ? ESCAPE '!'", ["%{$escaped}%"])
            ->orderByRaw("CASE WHEN LOWER(username) LIKE ? ESCAPE '!' THEN 0 ELSE 1 END", ["{$escaped}%"])
            ->orderBy('username')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function byIds(array $ids): Collection
    {
        return $ids === [] ? collect() : Trooper::query()->whereIn('id', $ids)->get()->keyBy('id')->toBase();
    }

    public function placedIn(int $sectionId): EloquentCollection
    {
        return Trooper::query()
            ->where('primary_section_id', $sectionId)
            ->whereIn('status', TrooperStatus::servingValues())
            ->orderBy('id')
            ->get();
    }

    public function placedInAny(iterable $sectionIds): EloquentCollection
    {
        return Trooper::query()
            ->whereIn('primary_section_id', collect($sectionIds)->all())
            ->whereIn('status', TrooperStatus::servingValues())
            ->orderBy('id')
            ->get();
    }

    public function cavIdsPlacedIn(array $sectionIds): array
    {
        return Trooper::query()
            ->whereIn('primary_section_id', $sectionIds)
            ->pluck('cav_id')
            ->map(fn ($cavId) => (int) $cavId)
            ->all();
    }
}
