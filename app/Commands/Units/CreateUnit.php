<?php

namespace App\Commands\Units;

use App\Models\Unit;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Add a node to the chain of command (AD-18). Admin-only — enforced by the controller
 * (AD-6) before this ever runs, not here, since the server-side gate is a request-shaped
 * concern and this command has no request to inspect.
 *
 * Guards the one domain rule request validation can't express on its own: echelon ordering.
 * `Rule::enum`/`exists:units,id` in the controller confirm `type` and `parent_id` are each
 * individually well-formed, but neither knows that a root must be a Regiment or that a child
 * must sit exactly one rank below its parent — that needs the parent's own type, which is
 * business logic, not request shape, so it lives here rather than in the controller.
 *
 * The path computation itself stays entirely in {@see UnitRepository::create()}, since Deptrac
 * keeps DB access there — duplicating it here would just be a second place for the two to
 * drift apart. The write itself is staged through `AuditEnvelope` (AD-16) — a reason is never
 * required for org-structure edits, only for personnel actions and report sends. The unit keeps who added it, so they
 * may undo the add ({@see DeleteUnit}).
 */
class CreateUnit
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * The name and callsign are derived here, never taken from the request: the regiment gets its fixed designation,
     * a line unit is built from its parent's designation and the picked `$ordinal` ({@see CavDesignation::childOf()}),
     * and only a group, which the import never names by rule, takes a free `$name` and no callsign.
     *
     * @throws InvalidUnitPlacementException when the requested placement breaks echelon
     *                                       ordering — caught by the controller and turned
     *                                       into a form validation error.
     * @throws InvalidUnitOrdinalException when the ordinal is out of range or already used under the parent, or a
     *                                     group has no name.
     */
    public function handle(User $actor, UnitType $type, ?int $parentId, ?string $ordinal = null, ?string $name = null): Unit
    {
        $parent = null;

        if ($parentId === null) {
            if ($type !== UnitType::Regiment) {
                throw InvalidUnitPlacementException::rootMustBeRegiment($type);
            }
        } else {
            // `parent_id` was already confirmed to exist by the controller's `exists:units,id`
            // rule, so a null find() here would mean that guarantee broke, not a user error —
            // let it surface as a TypeError from the null-safe operator below rather than
            // silently accept an inconsistent write.
            $parent = $this->units->find($parentId);

            if (! $type->canSitUnder($parent->type) || ! self::groupParentAllowed($type, $parent)) {
                throw InvalidUnitPlacementException::wrongRankUnderParent($type, $parent->type);
            }
        }

        [$name, $callsign] = match (true) {
            $type === UnitType::Regiment => [CavDesignation::REGIMENT_NAME, CavDesignation::REGIMENT_CALLSIGN],
            $type === UnitType::Group => [self::groupName($name), null],
            default => $this->derive($type, $parent, (string) $ordinal),
        };

        // A group made by hand is a staff group: ELOA, Reservists and New Recruits are the import's own, found by callsign.
        $kind = $type === UnitType::Group ? UnitKind::Staff : UnitKind::Line;

        return $this->audit->record(
            actorId: $actor->id,
            action: 'unit.created',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->units->create($type, $name, $callsign, $parentId, $kind, $actor->id),
        );
    }

    /**
     * @return array{0: string, 1: string} the name and callsign of the line unit `$ordinal` names under `$parent`
     */
    private function derive(UnitType $type, Unit $parent, string $ordinal): array
    {
        if ($type !== UnitType::Battalion && trim((string) $parent->callsign) === '') {
            throw InvalidUnitOrdinalException::parentHasNoDesignation();
        }

        $designation = CavDesignation::childOf($type, $parent->callsign, $ordinal)
            ?? throw InvalidUnitOrdinalException::invalid($type);

        $callsign = $designation->callsign();
        $taken = $this->units->children($parent)->contains(fn (Unit $sibling) => strcasecmp((string) $sibling->callsign, $callsign) === 0);

        if ($taken) {
            throw InvalidUnitOrdinalException::taken($designation->name());
        }

        return [$designation->name(), $callsign];
    }

    private static function groupName(?string $name): string
    {
        $name = trim((string) $name);

        return $name !== '' ? $name : throw InvalidUnitOrdinalException::nameRequired();
    }

    /**
     * A group nests only under a staff group (a department under its portfolio): never under ELOA, Reservists or New
     * Recruits, which hold troopers, not units. Shared with {@see MoveUnit}.
     */
    public static function groupParentAllowed(UnitType $type, Unit $parent): bool
    {
        return $type !== UnitType::Group || $parent->type !== UnitType::Group || $parent->kind === UnitKind::Staff;
    }
}
