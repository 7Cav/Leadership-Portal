<?php

namespace App\Commands\Units;

use App\Models\CavBillet;
use App\Models\User;
use App\Repositories\CavBilletRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Confirm that a placed billet the Cav renamed still belongs where it is placed (2026-11-13). A rename never moves or
 * drops the placement: it waits on Org structure, old name and new, until an Admin confirms it or moves the billet with
 * {@see AssignBillet}. The same check Staff grants make for an Admin billet, without the Admin rights.
 *
 * Admin-only, enforced by the controller (AD-6). Audited through the envelope (AD-16) as `billet.name_confirmed`,
 * targeting the billet. Nothing moves, so no reason is required.
 */
class ConfirmBilletName
{
    public const ACTION = 'billet.name_confirmed';

    public function __construct(
        private readonly CavBilletRepository $billets,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws InvalidBilletChangeException when the billet is unknown or has no new name
     */
    public function handle(User $actor, int $positionId): CavBillet
    {
        return $this->audit->record(
            actorId: $actor->id,
            action: self::ACTION,
            reason: null,
            reasonRequired: false,
            mutation: function () use ($positionId): CavBillet {
                $billet = $this->billets->lockForPosition($positionId) ?? throw InvalidBilletChangeException::unknown();

                if (! $billet->renamed()) {
                    throw InvalidBilletChangeException::notRenamed();
                }

                return $this->billets->confirmName($billet);
            },
        );
    }
}
