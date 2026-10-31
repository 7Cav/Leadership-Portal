<?php

namespace App\Commands\Practices;

use App\Models\UnitType;
use App\Models\User;
use App\Repositories\BattalionSettingRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Set a battalion's practice presence minimum (#558): how many minutes on the practice's server inside its hour count
 * as attending. Its HQ staff and Admins may ({@see PresenceMinimum::mayChange()}); acting as one of them writes as
 * usual, a view-as preview never does. Audited as `presence_minimum.set` (AD-16); a change that changes nothing is
 * not logged. Null goes back to the default. Applies to captures from now on: an earlier capture's marks stay.
 */
class SetPresenceMinimum
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly BattalionSettingRepository $settings,
        private readonly PresenceMinimum $minimum,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws PresenceMinimumForbiddenException when the unit is not a battalion the actor may change.
     * @throws InvalidPresenceMinimumException when the minutes are out of range.
     */
    public function handle(User $actor, int $battalionId, ?int $minutes): void
    {
        $battalion = $this->units->find($battalionId);

        if ($battalion === null || $battalion->type !== UnitType::Battalion || ! $this->minimum->mayChange($actor, $battalion)) {
            throw new PresenceMinimumForbiddenException;
        }

        if ($minutes !== null && ($minutes < PresenceMinimum::LOWEST || $minutes > PresenceMinimum::HIGHEST)) {
            throw new InvalidPresenceMinimumException;
        }

        if ($this->settings->presenceMinimum($battalionId) === $minutes) {
            return;
        }

        $this->audit->record($actor->id, 'presence_minimum.set', null, false, fn () => $this->settings->setPresenceMinimum($battalionId, $minutes));
    }
}
