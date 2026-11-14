<?php

namespace App\Commands\Units;

use App\Models\CavBilletAcknowledgement;
use App\Models\User;
use App\Repositories\CavBilletRepository;
use App\Repositories\UnitBilletMappingRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Acknowledge a billet in two units at once that is meant to be there (2026-11-13), such as the one Cav title of the two
 * Regimental Deputy Chiefs of Staff placed in both their groups. It drops out of the billets waiting on Org structure
 * and the sidebar's count, and stays marked as acknowledged on the units' Billets lists. The acknowledgement holds only
 * for the exact billets and units it was given for: once any of them is added, moved or removed, the warning is back.
 * Nothing moves.
 *
 * The conflict is named by one of its billets and its kind ({@see CavBilletRepository::conflicts()}): `title` for one
 * name in several units, `rule` for an Admin's placement against the rules.
 *
 * Admin-only, enforced by the controller (AD-6). Audited with a required reason (AD-16) as `billet.conflict_acknowledged`.
 */
class AcknowledgeBilletConflict
{
    public const ACTION = 'billet.conflict_acknowledged';

    public function __construct(
        private readonly CavBilletRepository $billets,
        private readonly UnitBilletMappingRepository $mappings,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws InvalidBilletChangeException when the billet is unknown, in no such conflict, or acknowledged already
     */
    public function handle(User $actor, int $positionId, string $kind, string $reason): CavBilletAcknowledgement
    {
        return $this->audit->record(
            actorId: $actor->id,
            action: self::ACTION,
            reason: $reason,
            reasonRequired: true,
            mutation: function () use ($positionId, $kind): CavBilletAcknowledgement {
                // Read again under a lock: an Admin or the import may have moved a billet since the page loaded.
                $this->billets->lockForPosition($positionId) ?? throw InvalidBilletChangeException::unknown();
                $this->mappings->lockAll();

                $conflict = collect($this->billets->conflicts())->first(
                    fn (array $conflict) => $conflict['kind'] === $kind
                        && collect($conflict['units'])->contains(fn (array $at) => $at['positionId'] === $positionId),
                ) ?? throw InvalidBilletChangeException::noConflict();

                if ($conflict['acknowledged']) {
                    throw InvalidBilletChangeException::alreadyAcknowledged();
                }

                return $this->billets->acknowledge($conflict['key'], $conflict['title'], $conflict['placements']);
            },
        );
    }
}
