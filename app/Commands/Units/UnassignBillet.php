<?php

namespace App\Commands\Units;

use App\Models\UnitBilletMapping;
use App\Models\User;
use App\Repositories\CavBilletRepository;
use App\Repositories\UnitBilletMappingRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Remove an Admin's placement of a billet from a unit's Billets list (2026-11-13). The billet goes back to where the
 * import's rules put it; one the import never placed has no placement any more, so its holders land in the unit their
 * billet commands, or nowhere, on the next roster sync. A placement the rules made is not removed here: it follows
 * the Cav.
 *
 * Admin-only, enforced by the controller (AD-6). Audited with a required reason (AD-16) as `billet.unassigned`.
 */
class UnassignBillet
{
    public const ACTION = 'billet.unassigned';

    public function __construct(
        private readonly CavBilletRepository $billets,
        private readonly UnitBilletMappingRepository $mappings,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws InvalidBilletChangeException when the billet has no placement an Admin made
     */
    public function handle(User $actor, int $positionId, string $reason): UnitBilletMapping
    {
        return $this->audit->record(
            actorId: $actor->id,
            action: self::ACTION,
            reason: $reason,
            reasonRequired: true,
            mutation: function () use ($positionId): UnitBilletMapping {
                $row = $this->mappings->lockAll()->get($positionId);

                if ($row === null || $row['importedUnitId'] === $row['unitId']) {
                    throw InvalidBilletChangeException::notAssigned();
                }

                $mapping = $this->mappings->unassign($positionId) ?? throw InvalidBilletChangeException::notAssigned();
                $billet = $this->billets->lockForPosition($positionId);

                return $billet === null ? $mapping : $mapping->setRelation('billet', $billet);
            },
        );
    }
}
