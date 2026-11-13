<?php

namespace App\Repositories\CavBilletRepository;

use App\Models\AdminBillet;
use App\Models\CavBillet;
use App\Models\CavBilletAcknowledgement;
use App\Models\UnitBilletMapping;
use App\Models\UnitCommandMapping;
use App\Repositories\CavBilletRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class Eloquent implements CavBilletRepository
{
    public function observe(array $seen, array $placedPositionIds, bool $complete): int
    {
        $rows = CavBillet::query()->get()->keyBy('cav_position_id');
        $placed = array_flip($placedPositionIds);
        $written = 0;

        foreach ($seen as $positionId => [$title, $group, $rule]) {
            $title = AdminBillet::name($title);

            if ($title === null) {
                continue;
            }

            $group = AdminBillet::name($group);
            $row = $rows->get($positionId) ?? new CavBillet(['cav_position_id' => $positionId]);
            $changed = $row->exists && ($title !== AdminBillet::name($row->title) || ($group !== null && $group !== AdminBillet::name($row->group_title)));

            $row->title = $title;
            $row->group_title = $group ?? $row->group_title;
            $row->rule = $rule ?? ($complete ? null : $row->rule);

            if ($row->confirmed_title === null && isset($placed[$positionId])) {
                $row->confirmed_title = $title;
            }

            if ($changed && $row->unplaced_at !== null) {
                $row->unplaced_at = null;
            }

            if ($row->isDirty()) {
                $row->save();
                $written++;
            }
        }

        if ($complete) {
            $written += CavBillet::query()->whereNotIn('cav_position_id', array_keys($seen))->delete();
        }

        return $written;
    }

    public function all(): Collection
    {
        return CavBillet::query()->orderBy('id')->get()->keyBy('cav_position_id')->toBase();
    }

    public function lockForPosition(int $positionId): ?CavBillet
    {
        return CavBillet::query()->where('cav_position_id', $positionId)->lockForUpdate()->first();
    }

    public function confirmName(CavBillet $billet): CavBillet
    {
        $billet->forceFill(['confirmed_title' => $billet->title, 'unplaced_at' => null])->save();

        return $billet;
    }

    public function leaveUnplaced(CavBillet $billet): CavBillet
    {
        $billet->forceFill(['unplaced_at' => now()])->save();

        return $billet;
    }

    public function attentionCount(): int
    {
        $renamed = CavBillet::query()
            ->whereNotNull('confirmed_title')
            ->whereColumn('confirmed_title', '!=', 'title')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('unit_billet_mappings')->whereColumn('unit_billet_mappings.cav_position_id', 'cav_billets.cav_position_id'))
            ->count();

        $conflicts = collect($this->conflicts())->reject(fn (array $conflict) => $conflict['acknowledged'])->count();

        return $renamed + $this->unassigned()->count() + $conflicts;
    }

    public function conflicts(): array
    {
        $titles = CavBillet::query()->pluck('title', 'cav_position_id');
        $placements = UnitBilletMapping::query()->get(['cav_position_id', 'unit_id', 'imported_unit_id']);
        $commands = UnitCommandMapping::query()->pluck('unit_id', 'cav_position_id');
        $conflicts = [];

        // One name, two or more units.
        $byTitle = $placements
            ->filter(fn (UnitBilletMapping $row) => $titles->has($row->cav_position_id))
            ->groupBy(fn (UnitBilletMapping $row) => mb_strtolower(trim((string) $titles->get($row->cav_position_id))));

        foreach ($byTitle as $rows) {
            if ($rows->pluck('unit_id')->unique()->count() < 2) {
                continue;
            }

            $title = (string) $titles->get($rows->first()->cav_position_id);
            $conflicts[] = [
                'kind' => 'title',
                'key' => $this->conflictKey('title', (int) $rows->first()->cav_position_id, $title),
                'title' => $title,
                'units' => $rows->sortBy('cav_position_id')->map(fn (UnitBilletMapping $row) => [
                    'unitId' => (int) $row->unit_id,
                    'positionId' => (int) $row->cav_position_id,
                    'source' => self::source($row),
                ])->values()->all(),
            ];
        }

        // One billet an Admin placed against what its rules say.
        foreach ($placements->sortBy('cav_position_id') as $row) {
            if (self::source($row) !== 'admin') {
                continue;
            }

            $units = [['unitId' => (int) $row->unit_id, 'positionId' => (int) $row->cav_position_id, 'source' => 'admin']];

            if ($row->imported_unit_id !== null) {
                $units[] = ['unitId' => (int) $row->imported_unit_id, 'positionId' => (int) $row->cav_position_id, 'source' => 'rule'];
            }

            $commanded = $commands->get($row->cav_position_id);
            if ($commanded !== null && (int) $commanded !== (int) $row->unit_id) {
                $units[] = ['unitId' => (int) $commanded, 'positionId' => (int) $row->cav_position_id, 'source' => 'command'];
            }

            if (count($units) > 1) {
                $title = (string) ($titles->get($row->cav_position_id) ?? "Position {$row->cav_position_id}");
                $conflicts[] = [
                    'kind' => 'rule',
                    'key' => $this->conflictKey('rule', (int) $row->cav_position_id, $title),
                    'title' => $title,
                    'units' => $units,
                ];
            }
        }

        // Acknowledged only while every billet sits exactly where it sat when an Admin acknowledged it.
        $acknowledged = CavBilletAcknowledgement::query()->pluck('placements', 'conflict_key');

        return array_map(function (array $conflict) use ($acknowledged): array {
            $placements = collect($conflict['units'])
                ->map(fn (array $at) => "{$at['positionId']}:{$at['unitId']}:{$at['source']}")
                ->sort()->implode(',');

            return [
                'kind' => $conflict['kind'],
                'key' => $conflict['key'],
                'title' => $conflict['title'],
                'placements' => $placements,
                'acknowledged' => $acknowledged->get($conflict['key']) === $placements,
                'units' => $conflict['units'],
            ];
        }, $conflicts);
    }

    public function conflictKey(string $kind, int $positionId, string $title): string
    {
        return $kind === 'title' ? 'title:'.mb_strtolower(trim($title)) : "rule:{$positionId}";
    }

    public function acknowledge(string $key, string $title, string $placements): CavBilletAcknowledgement
    {
        $row = CavBilletAcknowledgement::query()->where('conflict_key', $key)->lockForUpdate()->first()
            ?? new CavBilletAcknowledgement(['conflict_key' => $key]);
        $row->fill(['title' => $title, 'placements' => $placements])->save();

        return $row;
    }

    public function unacknowledge(string $key): ?CavBilletAcknowledgement
    {
        $row = CavBilletAcknowledgement::query()->where('conflict_key', $key)->lockForUpdate()->first();
        $row?->delete();

        return $row;
    }

    /** Why a placement lands where it does: the import's rules while it still points where the import put it. */
    private static function source(UnitBilletMapping $row): string
    {
        return $row->imported_unit_id !== null && (int) $row->imported_unit_id === (int) $row->unit_id ? 'rule' : 'admin';
    }

    /**
     * Billets nothing places: no placement, no command mapping, not left unplaced, not in a group of troopers who no
     * longer serve.
     *
     * @return Builder<CavBillet>
     */
    private function unassigned(): Builder
    {
        return CavBillet::query()
            ->whereNull('unplaced_at')
            ->where(fn ($query) => $query->whereNull('group_title')->orWhereNotIn('group_title', CavBillet::NOT_SERVING_GROUPS))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('unit_billet_mappings')->whereColumn('unit_billet_mappings.cav_position_id', 'cav_billets.cav_position_id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('unit_command_mappings')->whereColumn('unit_command_mappings.cav_position_id', 'cav_billets.cav_position_id'));
    }
}
