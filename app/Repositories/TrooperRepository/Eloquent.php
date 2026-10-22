<?php

namespace App\Repositories\TrooperRepository;

use App\Models\PlacementSource;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Repositories\TrooperRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Eloquent implements TrooperRepository
{
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
            ->update(['status' => TrooperStatus::Inactive->value]);
    }

    public function countDepartingWithStatus(TrooperStatus $status, array $activeCavIds): int
    {
        return Trooper::query()
            ->where('status', $status->value)
            ->whereNotIn('cav_id', $activeCavIds)
            ->count();
    }

    public function updatePrimarySections(array $cavIdToSectionId): int
    {
        $updated = 0;

        // Grouped by target section rather than one UPDATE per trooper: a full-roster sync can
        // resolve several hundred troopers into a handful of sections, and this turns that into
        // one query per section instead of one per trooper.
        foreach (collect($cavIdToSectionId)->groupBy(fn ($sectionId) => $sectionId, preserveKeys: true) as $sectionId => $cavIds) {
            $updated += Trooper::query()
                ->whereIn('cav_id', $cavIds->keys())
                ->update(['primary_section_id' => $sectionId, 'placement_source' => PlacementSource::Sync->value]);
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
        $placed = Trooper::query()
            ->whereKey($trooperId)
            ->whereNull('primary_section_id')
            ->whereIn('status', TrooperStatus::servingValues())
            ->update(['primary_section_id' => $sectionId, 'placement_source' => PlacementSource::Manual->value]);

        return $placed === 1 ? Trooper::find($trooperId) : null;
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

    public function rosterOf(Unit $unit): Collection
    {
        return Trooper::query()->assignedWithin($unit)->orderBy('id')->get()->toBase();
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

    public function sectionsOfAssignedWithin(Unit $unit): Collection
    {
        return Trooper::query()->assignedWithin($unit)->orderBy('id')
            ->pluck('primary_section_id', 'id')
            ->map(fn ($sectionId) => (int) $sectionId);
    }

    public function placedWithin(Unit $unit): EloquentCollection
    {
        return Trooper::query()
            ->whereIn('primary_section_id', Unit::query()->select('id')->where('path', 'like', "{$unit->path}%"))
            ->orderBy('id')
            ->get();
    }

    public function activePlacedWithin(Unit $unit): EloquentCollection
    {
        return Trooper::query()->assignedWithin($unit)->orderBy('id')->get();
    }

    public function find(int $id): ?Trooper
    {
        return Trooper::query()->find($id);
    }

    public function searchByUsername(string $term, iterable $sectionIds, bool $withUnplaced, int $limit): EloquentCollection
    {
        // `!` escapes the LIKE wildcards: one escape character both SQLite and MySQL accept without quoting games.
        $escaped = strtr(mb_strtolower($term), ['!' => '!!', '%' => '!%', '_' => '!_']);

        return Trooper::query()
            ->where(fn (Builder $scope) => $scope
                ->whereIn('primary_section_id', collect($sectionIds)->all())
                ->when($withUnplaced, fn (Builder $q) => $q->orWhereNull('primary_section_id')))
            ->whereRaw("LOWER(username) LIKE ? ESCAPE '!'", ["%{$escaped}%"])
            ->orderByRaw('CASE WHEN status IN ('.implode(', ', array_fill(0, count(TrooperStatus::serving()), '?')).') THEN 0 ELSE 1 END', TrooperStatus::servingValues())
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
