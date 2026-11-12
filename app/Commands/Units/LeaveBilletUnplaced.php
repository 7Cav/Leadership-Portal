<?php

namespace App\Commands\Units;

use App\Models\CavBillet;
use App\Models\User;
use App\Repositories\CavBilletRepository;
use App\Repositories\UnitBilletMappingRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Leave a new billet unplaced (2026-11-13), such as a start-up game's billet that belongs to no unit yet. It stops asking
 * for a unit until the Cav renames it or lists it in another group. Its holders stay unplaced, as before.
 *
 * Admin-only, enforced by the controller (AD-6). Audited with a required reason (AD-16) as `billet.left_unplaced`,
 * targeting the billet.
 */
class LeaveBilletUnplaced
{
    public const ACTION = 'billet.left_unplaced';

    public function __construct(
        private readonly CavBilletRepository $billets,
        private readonly UnitBilletMappingRepository $mappings,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws InvalidBilletChangeException when the billet is unknown or placed already
     */
    public function handle(User $actor, int $positionId, string $reason): CavBillet
    {
        return $this->audit->record(
            actorId: $actor->id,
            action: self::ACTION,
            reason: $reason,
            reasonRequired: true,
            mutation: function () use ($positionId): CavBillet {
                $billet = $this->billets->lockForPosition($positionId) ?? throw InvalidBilletChangeException::unknown();

                if ($this->mappings->unitIdsByPositionId()->has($positionId)) {
                    throw InvalidBilletChangeException::alreadyPlaced();
                }

                return $this->billets->leaveUnplaced($billet);
            },
        );
    }
}
