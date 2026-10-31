<?php

namespace App\Commands\Practices;

use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\SectionPracticeSlotRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitGame;
use App\Repositories\UnitRepository;

/**
 * Which sections have no weekly practice slot set (#392) while they need one: without a slot their practice is never
 * captured from the game server, so nobody gets an automatic make-up there. A section counts when it is a line section
 * (not a group such as ELOA or Reservists), plays a game ({@see UnitGame}, its own or the nearest one above) and has
 * someone serving placed in it.
 *
 * Read-only, and a fixed number of queries however many sections are asked about: the tree, the headcounts and the
 * slots, each once.
 */
class SectionsWithoutSlot
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly SectionPracticeSlotRepository $slots,
    ) {}

    /**
     * The sections among `$unitIds` with no slot, in tree order, as `{id, designation}`. Empty when every counted
     * section has one.
     *
     * @param  iterable<int>  $unitIds
     * @return list<array{id: int, designation: string}>
     */
    public function among(iterable $unitIds): array
    {
        $wanted = collect($unitIds)->map(fn ($id) => (int) $id)->flip();

        if ($wanted->isEmpty()) {
            return [];
        }

        $tree = $this->units->tree();
        $sections = $tree->filter(fn (Unit $unit) => $unit->type === UnitType::Section && $unit->isLine() && $wanted->has($unit->id));

        if ($sections->isEmpty()) {
            return [];
        }

        $games = UnitGame::resolve($tree);
        $counts = $this->troopers->countsBySection();
        $counted = $sections->filter(fn (Unit $unit) => isset($games[$unit->id]) && $counts->get($unit->id, 0) > 0);

        if ($counted->isEmpty()) {
            return [];
        }

        $withSlot = $this->slots->forSections($counted->pluck('id')->all());

        return $counted
            ->reject(fn (Unit $unit) => $withSlot->has($unit->id))
            ->map(fn (Unit $unit) => ['id' => $unit->id, 'designation' => $unit->callsign ?? $unit->name])
            ->values()
            ->all();
    }
}
