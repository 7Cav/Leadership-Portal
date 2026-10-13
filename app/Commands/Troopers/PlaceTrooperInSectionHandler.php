<?php

namespace App\Commands\Troopers;

use App\Models\Trooper;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Audit\ReasonRequiredException;

/**
 * Place an unmapped trooper into a section by hand (2.7, AD-18). This is the one audited exception to
 * the roster sync being the sole writer of `troopers`: it writes only `primary_section_id` and
 * `placement_source = manual`, never a synced roster field (AD-4, AD-22).
 *
 * Admin only, checked here against the real flag (AD-6), then refused before anything is written when
 * the target is not a section, or the trooper does not exist, is already placed, or has left the Cav
 * roster. The write itself is one audited transaction (AD-16) with a required reason: a blank reason is
 * rejected and leaves no state change and no log entry. The repository applies the placement only while
 * the trooper is still unplaced, so a sync that placed them a moment earlier wins and this refuses.
 *
 * The placement survives later syncs: the mapping step only writes troopers it can resolve. Once Cav
 * resolves this trooper's billet, the mapping takes over and `placement_source` flips to `sync`.
 */
class PlaceTrooperInSectionHandler
{
    public function __construct(
        private readonly TrooperRepository $troopers,
        private readonly UnitRepository $units,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws PlaceTrooperForbiddenException when the actor is not an Admin.
     * @throws InvalidTrooperPlacementException when the target is not a section, or the trooper cannot be placed.
     * @throws ReasonRequiredException when the reason is blank.
     */
    public function handle(User $actor, PlaceTrooperInSection $command): Trooper
    {
        if (! $actor->is_admin) {
            throw new PlaceTrooperForbiddenException;
        }

        if ($this->units->find($command->sectionId)?->type !== UnitType::Section) {
            throw InvalidTrooperPlacementException::notASection($command->sectionId);
        }

        $trooper = $this->troopers->find($command->trooperId)
            ?? throw InvalidTrooperPlacementException::noSuchTrooper($command->trooperId);

        if ($trooper->primary_section_id !== null) {
            throw InvalidTrooperPlacementException::alreadyPlaced($trooper->id);
        }

        if (! $trooper->status->isServing()) {
            throw InvalidTrooperPlacementException::departed($trooper->id);
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'trooper.placed',
            reason: $command->reason,
            reasonRequired: true,
            mutation: fn () => $this->troopers->place($trooper->id, $command->sectionId)
                ?? throw InvalidTrooperPlacementException::changedMeanwhile($trooper->id),
        );
    }
}
