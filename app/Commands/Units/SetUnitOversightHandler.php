<?php

namespace App\Commands\Units;

use App\Authorization\ScopeResolver;
use App\Models\UnitOversight;
use App\Models\User;
use App\Repositories\AuditedBatch;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitOversightRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;
use App\Support\Audit\ReasonRequiredException;

/**
 * Set or clear who oversees a unit (decided by the S6, 2026-10-29): a regimental staff officer over a battalion or the
 * Reservists, such as an Adjutant General over 2-7 or the RDC Commander over ACD. Cav's API does not carry it, so an
 * Admin enters it here (AD-22). ScopeResolver then gives the overseer read-only scope over the unit's subtree while they
 * serve, exactly like a staff grant, and never write.
 *
 * Admin only, checked against `ScopeResolver::isAdmin()` (AD-6). One overseer per unit: setting another replaces them.
 * The overseer must be a serving trooper; they need no portal account. Audited with a required reason (AD-16) as
 * `unit.oversight_set` or `unit.oversight_cleared`, targeting the unit so the audit log's unit filter finds both.
 */
class SetUnitOversightHandler
{
    public const SET = 'unit.oversight_set';

    public const CLEARED = 'unit.oversight_cleared';

    public function __construct(
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly UnitOversightRepository $oversights,
        private readonly AuditEnvelope $audit,
        private readonly ScopeResolver $scope,
        private readonly ActingFor $acting,
    ) {}

    /**
     * @return UnitOversight|null the row set, or the row cleared
     *
     * @throws UnitOversightForbiddenException when the actor is not an Admin
     * @throws InvalidUnitOversightException when the unit or trooper is refused
     * @throws ReasonRequiredException when the reason is blank
     */
    public function handle(User $actor, SetUnitOversight $command): ?UnitOversight
    {
        if (! $this->scope->isAdmin($actor)) {
            throw new UnitOversightForbiddenException;
        }

        $unit = $this->units->find($command->unitId) ?? throw InvalidUnitOversightException::noSuchUnit();
        $designation = $unit->callsign ?? $unit->name;

        if (! UnitOversight::canOversee($unit)) {
            throw InvalidUnitOversightException::notOverseeable($designation);
        }

        $current = $this->oversights->forUnit($unit->id);
        $row = null;

        if ($command->trooperId === null) {
            if ($current === null) {
                throw InvalidUnitOversightException::noneToClear($designation);
            }

            // Targets the unit, not the removed row, so the entry still matches the audit log's unit filter once the
            // row is gone; the digest takes the trooper who oversaw it.
            $this->audit->recordBatch(
                actorId: $actor->id,
                action: self::CLEARED,
                reason: $command->reason,
                reasonRequired: true,
                mutation: function () use ($unit, $designation, &$row) {
                    $row = $this->oversights->clear($unit->id) ?? throw InvalidUnitOversightException::noneToClear($designation);

                    return new AuditedBatch($unit, $row->trooper);
                },
            );

            return $row;
        }

        $trooper = $this->troopers->find($command->trooperId) ?? throw InvalidUnitOversightException::noSuchTrooper();

        if (! $trooper->status->isServing()) {
            throw InvalidUnitOversightException::notServing($trooper->display_name);
        }

        if ($current !== null && $current->trooper_id === $trooper->id) {
            throw InvalidUnitOversightException::unchanged($designation, $trooper->display_name);
        }

        $this->audit->recordBatch(
            actorId: $actor->id,
            action: self::SET,
            reason: $command->reason,
            reasonRequired: true,
            mutation: function () use ($unit, $trooper, $actor, &$row) {
                $row = $this->oversights->set(
                    $unit->id,
                    $trooper->id,
                    $actor->id,
                    $this->acting->adminActingAs($actor->id),
                    $this->acting->billetOf($actor->id),
                );

                return new AuditedBatch($unit, $row);
            },
        );

        return $row;
    }
}
