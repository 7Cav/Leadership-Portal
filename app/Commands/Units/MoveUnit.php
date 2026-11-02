<?php

namespace App\Commands\Units;

use App\Authorization\ScopeResolver;
use App\Commands\Templates\CarryUnitTemplates;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AuditedBatch;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;
use Illuminate\Support\Collection;

/**
 * Re-parent a unit (AD-18). Admin-only — enforced by the controller (AD-6), same as
 * {@see CreateUnit}. Guards the same echelon-ordering rule `CreateUnit` guards, plus the one
 * placement error only a move can create: a cycle, where the chosen parent is one of the unit's
 * own descendants (or the unit itself). The transactional subtree path recompute stays in
 * {@see UnitRepository::move()}, since Deptrac keeps DB access there. A no-op move (the
 * requested parent is already the unit's current parent) never reaches `AuditEnvelope` — no
 * state changes, so nothing is logged.
 *
 * A designation always follows the parent (2026-11-02): the moved unit keeps its number or letter and rebuilds its
 * callsign from the new parent, and so does everything under it, so platoon `1/A/1-7` moved under `G/1-7` becomes
 * `1/G/1-7` and its sections `1/1/G/1-7` and on. A name that is still the derived one (`1st Platoon`) follows the new
 * number; a name an Admin chose is kept. A unit whose callsign has no designation shape (a group, `ACD`) keeps it.
 * When the new callsign is already held under the new parent the move is refused unless it says how to settle it
 * ({@see ClashResolution}): the two trade places, the unit there takes another free number, or the moved one does.
 * The unit already there must be in the actor's write scope. The clash is checked under lock inside the one audited
 * write, so a unit added meanwhile refuses the move rather than leaving two with one designation; every renumbered
 * unit is listed in the entry with its callsign before and after.
 *
 * A platoon or section that lands under another company keeps its settings and what its posts render (#378): the
 * templates its sections used are copied into the new company and their picks moved to the copies, in the same
 * transaction and audited with the move ({@see CarryUnitTemplates}).
 */
class MoveUnit
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly AuditEnvelope $audit,
        private readonly CarryUnitTemplates $carry,
        private readonly ScopeResolver $scope,
    ) {}

    /**
     * @param  string|null  $ordinal  the free number or letter a renumber takes ({@see ClashResolution::RenumberExisting},
     *                                {@see ClashResolution::RenumberMoved})
     *
     * @throws InvalidUnitPlacementException when the requested placement breaks echelon
     *                                       ordering or introduces a cycle — caught by the
     *                                       controller and turned into a form validation error.
     * @throws UnitDesignationClashException when the new designation is taken and no way to settle it applies.
     * @throws InvalidUnitOrdinalException when the picked number or letter is not one or is taken.
     */
    public function handle(User $actor, Unit $unit, ?int $newParentId, ?ClashResolution $resolution = null, ?string $ordinal = null): Unit
    {
        if ($newParentId === $unit->parent_id) {
            return $unit;
        }

        if ($newParentId === null) {
            if ($unit->type !== UnitType::Regiment) {
                throw InvalidUnitPlacementException::rootMustBeRegiment($unit->type);
            }

            return $unit;
        }

        // `parent_id` was already confirmed to exist by the controller's `exists:units,id`
        // rule, so a null find() here would mean that guarantee broke, not a user error — let
        // it fail loudly below (a null `$newParent` breaks on the first property access)
        // rather than silently accept an inconsistent write.
        $newParent = $this->units->find($newParentId);

        if (str_starts_with($newParent->path, $unit->path)) {
            throw InvalidUnitPlacementException::cyclicParent($unit->name, $newParent->name);
        }

        if (! $unit->type->canSitUnder($newParent->type) || ! CreateUnit::groupParentAllowed($unit->type, $newParent)) {
            throw InvalidUnitPlacementException::wrongRankUnderParent($unit->type, $newParent->type);
        }

        $oldParent = $unit->parent_id === null ? null : $this->units->find($unit->parent_id);

        // Read while the unit still sits under its old company: what its sections render from there.
        [$fromCompany, $used] = $this->carry->usedBy($unit);

        $batch = $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'unit.moved',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($actor, $unit, $newParent, $oldParent, $resolution, $ordinal, $fromCompany, $used) {
                // Each entry: the unit, the parent it ends under, the callsign of the parent it had, its new ordinal.
                [$plans, $swapped] = $this->plan($actor, $unit, $newParent, $oldParent, $resolution, $ordinal);
                [$swapFrom, $swapUsed] = $swapped === null ? [null, []] : $this->carry->usedBy($swapped);

                $moved = $this->units->move($unit, $newParent->id);
                if ($swapped !== null) {
                    $this->units->move($swapped, $oldParent->id);
                }

                $members = [];
                foreach ($plans as [$top, $parent, $fromCallsign, $topOrdinal]) {
                    $fresh = $this->units->find($top->id);
                    $subtree = $this->units->subtree($fresh);
                    foreach (self::rebuild($subtree, $fresh, $fromCallsign, $parent->callsign, $topOrdinal) as $id => [$name, $callsign]) {
                        $members[$id] = $this->units->rename($subtree->firstWhere('id', $id), $name, $callsign);
                    }
                }

                $this->carry->afterMove($actor, $moved, $fromCompany, $used);
                if ($swapped !== null) {
                    $this->carry->afterMove($actor, $this->units->find($swapped->id), $swapFrom, $swapUsed);
                }

                $target = $members[$unit->id] ?? $moved;
                unset($members[$unit->id]);

                return new AuditedBatch($target, ...array_values($members));
            },
        );

        return $batch->target;
    }

    /**
     * What the move renumbers, read under lock: `[plans, the unit that swaps back or null]`, each plan
     * `[unit, parent it ends under, callsign of the parent it had, its ordinal]`.
     *
     * @return array{0: list<array{0: Unit, 1: Unit, 2: ?string, 3: string}>, 1: ?Unit}
     */
    private function plan(User $actor, Unit $unit, Unit $newParent, ?Unit $oldParent, ?ClashResolution $resolution, ?string $ordinal): array
    {
        $own = CavDesignation::ordinalOf($unit->type, $unit->callsign);
        $target = $own === null ? null : CavDesignation::childOf($unit->type, $newParent->callsign, $own);

        // No designation to rebuild: the unit and everything under it keep their callsigns.
        if ($target === null) {
            return [[], null];
        }

        $siblings = $this->units->lockedChildren($newParent)->reject(fn (Unit $sibling) => $sibling->id === $unit->id);
        $holds = fn (Unit $sibling, string $callsign) => strcasecmp((string) $sibling->callsign, $callsign) === 0;
        $clash = $siblings->first(fn (Unit $sibling) => $holds($sibling, $target->callsign()));
        $parentLabel = $newParent->callsign ?? $newParent->name;
        $moved = [$unit, $newParent, $oldParent?->callsign, $own];

        if ($resolution === ClashResolution::RenumberMoved) {
            $pick = $this->pick($unit->type, $newParent, $ordinal, $siblings);

            return [[[$unit, $newParent, $oldParent?->callsign, $pick]], null];
        }

        if ($clash === null) {
            if ($resolution !== null) {
                throw UnitDesignationClashException::gone($target->callsign(), $parentLabel);
            }

            return [[$moved], null];
        }

        if ($resolution === null) {
            throw UnitDesignationClashException::exists($target->callsign(), $parentLabel);
        }

        if (! $this->scope->writeScope($actor)->contains($clash->id)) {
            throw UnitDesignationClashException::outOfScope((string) $clash->callsign);
        }

        if ($resolution === ClashResolution::RenumberExisting) {
            $pick = $this->pick($clash->type, $newParent, $ordinal, $siblings->reject(fn (Unit $sibling) => $sibling->id === $clash->id), $target->callsign());

            return [[[$clash, $newParent, $newParent->callsign, $pick], $moved], null];
        }

        // Swap: the unit already there takes the moved unit's place, under its old parent with its old callsign.
        if ($oldParent === null || ! $clash->type->canSitUnder($oldParent->type) || ! CreateUnit::groupParentAllowed($clash->type, $oldParent)) {
            throw UnitDesignationClashException::cannotSwap((string) $clash->callsign);
        }
        $left = $this->units->lockedChildren($oldParent)->reject(fn (Unit $sibling) => in_array($sibling->id, [$unit->id, $clash->id], true));
        if ($left->contains(fn (Unit $sibling) => $holds($sibling, (string) $unit->callsign))) {
            throw UnitDesignationClashException::swapTaken((string) $unit->callsign, $oldParent->callsign ?? $oldParent->name);
        }

        return [[$moved, [$clash, $oldParent, $newParent->callsign, $own]], $clash];
    }

    /**
     * The number or letter a renumber takes: one {@see CavDesignation::childOf()} builds under `$parent`, held by none of
     * `$siblings` and not `$reserved` (the callsign the moved unit is about to take).
     *
     * @param  Collection<int, Unit>  $siblings
     */
    private function pick(UnitType $type, Unit $parent, ?string $ordinal, Collection $siblings, ?string $reserved = null): string
    {
        if (trim((string) $ordinal) === '') {
            throw UnitDesignationClashException::ordinalRequired();
        }

        $designation = CavDesignation::childOf($type, $parent->callsign, (string) $ordinal) ?? throw InvalidUnitOrdinalException::invalid($type);
        $callsign = $designation->callsign();

        if (($reserved !== null && strcasecmp($reserved, $callsign) === 0)
            || $siblings->contains(fn (Unit $sibling) => strcasecmp((string) $sibling->callsign, $callsign) === 0)) {
            throw InvalidUnitOrdinalException::taken($callsign);
        }

        return strtoupper(trim((string) $ordinal));
    }

    /**
     * The new name and callsign of every unit in `$subtree` (which holds `$top` and everything under it) once `$top`
     * sits under a parent with `$toCallsign` as `$ordinal`, keyed by unit id; only units that change are listed. Each
     * unit below keeps its own number or letter and builds on its parent's new callsign, so sections always follow
     * their platoon. A name still equal to the derived one follows the new designation; any other name is kept. A unit
     * whose designation cannot be rebuilt keeps its callsign, and so does everything under it.
     *
     * @param  Collection<int, Unit>  $subtree
     * @return array<int, array{0: string, 1: string}>
     */
    public static function rebuild(Collection $subtree, Unit $top, ?string $fromCallsign, ?string $toCallsign, string $ordinal): array
    {
        $byId = $subtree->keyBy('id');
        $rebuilt = [];
        $changes = [];

        foreach ($subtree->sortBy(fn (Unit $unit) => substr_count((string) $unit->path, '/'))->values() as $unit) {
            $current = CavDesignation::ordinalOf($unit->type, $unit->callsign);

            if ($unit->id === $top->id) {
                [$from, $to, $as] = [$fromCallsign, $toCallsign, $ordinal];
            } elseif (isset($rebuilt[$unit->parent_id]) && $current !== null) {
                [$from, $to, $as] = [$byId->get($unit->parent_id)?->callsign, $rebuilt[$unit->parent_id], $current];
            } else {
                continue;
            }

            $next = CavDesignation::childOf($unit->type, $to, $as);
            if ($next === null) {
                continue;
            }
            $rebuilt[$unit->id] = $next->callsign();

            $was = CavDesignation::childOf($unit->type, $from, $current ?? $as);
            $name = $was !== null && $unit->name === $was->name() ? $next->name() : $unit->name;

            if ($next->callsign() !== $unit->callsign || $name !== $unit->name) {
                $changes[$unit->id] = [$name, $next->callsign()];
            }
        }

        return $changes;
    }
}
