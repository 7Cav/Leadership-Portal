<?php

namespace App\Commands\Units;

use App\Models\CavBilletAcknowledgement;
use App\Models\User;
use App\Repositories\CavBilletRepository;
use App\Repositories\UnitBilletMappingRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Undo an Admin's acknowledgement of a billet in two units at once ({@see AcknowledgeBilletConflict}, 2026-11-13): the
 * warning is back on Org structure and in the sidebar's count. Nothing moves.
 *
 * Admin-only, enforced by the controller (AD-6). Audited with a required reason (AD-16) as
 * `billet.conflict_unacknowledged`, as the acknowledgement was.
 */
class UndoBilletAcknowledgement
{
    public const ACTION = 'billet.conflict_unacknowledged';

    public function __construct(
        private readonly CavBilletRepository $billets,
        private readonly UnitBilletMappingRepository $mappings,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws InvalidBilletChangeException when the billet is unknown or its conflict is not acknowledged as it sits now
     */
    public function handle(User $actor, int $positionId, string $kind, string $reason): CavBilletAcknowledgement
    {
        return $this->audit->record(
            actorId: $actor->id,
            action: self::ACTION,
            reason: $reason,
            reasonRequired: true,
            mutation: function () use ($positionId, $kind): CavBilletAcknowledgement {
                $billet = $this->billets->lockForPosition($positionId) ?? throw InvalidBilletChangeException::unknown();
                $this->mappings->lockAll();
                $key = $this->billets->conflictKey($kind, $positionId, $billet->title);

                // Only an acknowledgement that still holds: one for other placements is not shown, so not undone.
                $holds = collect($this->billets->conflicts())->contains(fn (array $conflict) => $conflict['key'] === $key && $conflict['acknowledged']);

                if (! $holds) {
                    throw InvalidBilletChangeException::notAcknowledged();
                }

                return $this->billets->unacknowledge($key) ?? throw InvalidBilletChangeException::notAcknowledged();
            },
        );
    }
}
