<?php

namespace App\Http\Controllers\Settings;

use App\Models\CavBillet;
use App\Models\Unit;
use App\Repositories\CavBilletRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitBilletMappingRepository;
use App\Repositories\UnitCommandMappingRepository;
use Illuminate\Support\Collection;

/**
 * The billets Org structure shows an Admin (2026-11-13): each unit's billets, the placed billets the Cav renamed, the
 * new billets nothing places, the billets in two units at once (those an Admin acknowledged only on the units' lists,
 * marked as such), and every billet the Cav lists for the Add billet
 * picker. Read only; the writes are {@see OrgBilletsController}'s.
 *
 * A billet's source says why it lands in its unit: `rule` when the import placed it there by its rules (the rule in
 * words beside it), `admin` when an Admin placed it by hand, `command` when it has no placement of its own and its
 * holder lands in the unit the billet commands.
 */
final class OrgBilletList
{
    public function __construct(
        private readonly CavBilletRepository $billets,
        private readonly UnitBilletMappingRepository $mappings,
        private readonly UnitCommandMappingRepository $commands,
        private readonly TrooperRepository $troopers,
    ) {}

    /**
     * @param  Collection<int, Unit>  $tree
     * @return array{byUnit: object, conflicts: list<array<string, mixed>>, conflictsByUnit: object, catalog: list<array<string, mixed>>, renamed: list<array<string, mixed>>, new: list<array<string, mixed>>}
     */
    public function build(Collection $tree): array
    {
        $catalog = $this->billets->all();
        $placements = $this->mappings->forImportByPositionId();
        $commands = $this->commands->unitIdsByPositionId();
        $units = $tree->keyBy('id');
        $holders = $this->troopers->servingHoldingPositions(
            $catalog->keys()->merge($placements->keys())->merge($commands->keys())->unique()->values()->all(),
        );
        $title = fn (int $positionId) => $catalog->get($positionId)?->title
            ?? $holders->get($positionId)?->first()?->billet
            ?? "Position {$positionId}";
        $label = fn (?Unit $unit) => $unit === null ? null : ($unit->callsign ?? $unit->name);

        $byUnit = [];
        $renamed = [];

        foreach ($placements as $positionId => ['unitId' => $unitId, 'importedUnitId' => $importedUnitId]) {
            $billet = $catalog->get($positionId);
            $row = [
                'positionId' => $positionId,
                'title' => $title($positionId),
                'renamedFrom' => $billet?->renamed() ? $billet->confirmed_title : null,
                'holders' => $holders->get($positionId)?->count() ?? 0,
                'source' => $importedUnitId === $unitId ? 'rule' : 'admin',
                'rule' => $importedUnitId === $unitId ? $billet?->rule : null,
                'movable' => $billet !== null,
                // Only an Admin's placement is removed by hand; a rule's follows the Cav.
                'removable' => $importedUnitId !== $unitId,
            ];
            $byUnit[$unitId][] = $row;

            if ($row['renamedFrom'] !== null) {
                $renamed[] = [...$row, 'unitId' => $unitId, 'unitLabel' => $label($units->get($unitId))];
            }
        }

        foreach ($commands as $positionId => $unitId) {
            if (! $placements->has($positionId)) {
                $byUnit[$unitId][] = [
                    'positionId' => $positionId,
                    'title' => $title($positionId),
                    'renamedFrom' => null,
                    'holders' => $holders->get($positionId)?->count() ?? 0,
                    'source' => 'command',
                    'rule' => null,
                    // An Admin may still place it somewhere: an explicit placement beats its command for the sync.
                    'movable' => $catalog->has($positionId),
                    'removable' => false,
                ];
            }
        }

        $new = $catalog
            ->filter(fn (CavBillet $billet) => $billet->unplaced_at === null && ! $billet->notServing()
                && ! $placements->has($billet->cav_position_id) && ! $commands->has($billet->cav_position_id))
            ->map(fn (CavBillet $billet) => [
                'positionId' => $billet->cav_position_id,
                'title' => $billet->title,
                'group' => $billet->group_title,
                'holders' => $holders->get($billet->cav_position_id)?->count() ?? 0,
            ])
            // Held billets first: their troopers are the ones waiting for a unit.
            ->sortBy(fn (array $row) => [$row['holders'] === 0, mb_strtolower($row['title']), $row['positionId']])
            ->values()
            ->all();

        $sorted = array_map(
            fn (array $rows) => collect($rows)->sortBy(fn (array $row) => [mb_strtolower($row['title']), $row['positionId']])->values()->all(),
            $byUnit,
        );

        // Billets in two units at once, on the summary and on the list of every unit involved. One an Admin acknowledged
        // stays off the summary and reads as acknowledged on the lists. `positionId` names it for Acknowledge and Undo.
        $conflicts = collect($this->billets->conflicts())->map(fn (array $conflict) => [
            ...collect($conflict)->except('placements')->all(),
            'positionId' => $conflict['units'][0]['positionId'],
            'units' => array_map(fn (array $at) => [...$at, 'label' => $label($units->get($at['unitId'])) ?? 'a unit since deleted'], $conflict['units']),
        ]);
        $conflictsByUnit = [];
        foreach ($conflicts as $conflict) {
            foreach (collect($conflict['units'])->pluck('unitId')->unique() as $unitId) {
                $conflictsByUnit[$unitId][] = $conflict;
            }
        }

        // Every billet the Cav lists, for a unit's Add billet picker: where it lands now, if anywhere.
        $catalogRows = $catalog->map(fn (CavBillet $billet) => [
            'positionId' => $billet->cav_position_id,
            'title' => $billet->title,
            'group' => $billet->group_title,
            'unitId' => $placements->get($billet->cav_position_id)['unitId'] ?? $commands->get($billet->cav_position_id),
        ])->sortBy(fn (array $row) => [mb_strtolower($row['title']), $row['positionId']])->values()->all();

        return [
            'byUnit' => (object) $sorted,
            'conflicts' => $conflicts->reject(fn (array $conflict) => $conflict['acknowledged'])->values()->all(),
            'conflictsByUnit' => (object) $conflictsByUnit,
            'catalog' => $catalogRows,
            'renamed' => collect($renamed)->sortBy(fn (array $row) => [mb_strtolower($row['title']), $row['positionId']])->values()->all(),
            'new' => $new,
        ];
    }
}
