<?php

namespace App\Commands\Units;

use App\Models\Unit;
use App\Models\User;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;
use Carbon\CarbonImmutable;

/**
 * Deactivate a unit that no longer exists in the real org, or reactivate it. An inactive unit and everything under it
 * are left out of every list of units ({@see UnitRepository::tree()}): the roster, Home, the reports and roll-ups, the
 * pickers, staff reports, Staff grants and the headcounts. Nothing is deleted, and nobody placed in it is moved: they
 * count nowhere and read as unplaced until an Admin moves them or reactivates the unit. Reports already sent keep
 * what they froze.
 *
 * Admin-only, enforced by the controller (AD-6). Audited through the envelope (AD-16) as an org-structure edit, with an
 * optional reason. The regiment cannot be deactivated, nor a unit already inactive; only a unit deactivated itself can
 * be reactivated, which brings back everything under it that is not deactivated on its own.
 */
class SetUnitActive
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws InvalidUnitActivationException
     */
    public function deactivate(User $actor, Unit $unit, ?string $reason): Unit
    {
        if ($unit->parent_id === null) {
            throw InvalidUnitActivationException::regiment();
        }

        if ($this->units->findActive($unit->id) === null) {
            throw InvalidUnitActivationException::alreadyInactive();
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'unit.deactivated',
            reason: self::reason($reason),
            reasonRequired: false,
            mutation: fn () => $this->units->setDeactivated($unit, CarbonImmutable::now(), $actor->id),
        );
    }

    /**
     * @throws InvalidUnitActivationException
     */
    public function reactivate(User $actor, Unit $unit, ?string $reason): Unit
    {
        if ($unit->deactivated_at === null) {
            throw InvalidUnitActivationException::notDeactivated();
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'unit.reactivated',
            reason: self::reason($reason),
            reasonRequired: false,
            mutation: fn () => $this->units->setDeactivated($unit, null, null),
        );
    }

    private static function reason(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : $reason;
    }
}
