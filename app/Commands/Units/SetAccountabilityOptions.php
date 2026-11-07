<?php

namespace App\Commands\Units;

use App\Models\User;
use App\Repositories\UnitAccountabilitySettingRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Set a unit's own accountability options ({@see AccountabilityRules}). Who may is {@see AccountabilityRules::mayChange()}:
 * an Admin or Regimental HQ staff on a battalion, an Admin or the battalion's HQ staff on a unit under it. Under a
 * battalion only options its set allows may be ticked: a unit narrows, never widens. Audited as `accountability.set`
 * (AD-16); a change that changes nothing is not logged. Null clears the unit's own options, so it follows the units
 * above it again. A battalion narrowed later leaves the units under it as stored; they are cut down to its set when read.
 */
class SetAccountabilityOptions
{
    public const ACTION = 'accountability.set';

    public function __construct(
        private readonly UnitRepository $units,
        private readonly UnitAccountabilitySettingRepository $settings,
        private readonly AccountabilityRules $rules,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @param  list<string>|null  $options
     *
     * @throws AccountabilityOptionsForbiddenException when the unit has no options or the actor may not change them.
     * @throws InvalidAccountabilityOptionsException when an option is unknown, none is ticked, or one is beyond the battalion's.
     */
    public function handle(User $actor, int $unitId, ?array $options): void
    {
        $unit = $this->units->find($unitId);

        if ($unit === null || ! $this->rules->mayChange($actor, $unit)) {
            throw new AccountabilityOptionsForbiddenException;
        }

        if ($options !== null) {
            if (array_diff($options, array_keys(AccountabilityRules::OPTIONS)) !== []) {
                throw InvalidAccountabilityOptionsException::unknown();
            }

            $options = AccountabilityRules::ordered($options);

            if ($options === []) {
                throw InvalidAccountabilityOptionsException::empty();
            }

            if (array_diff($options, $this->rules->effective($unit)['allowed']) !== []) {
                throw InvalidAccountabilityOptionsException::wider();
            }
        }

        $stored = $this->settings->all()[$unitId] ?? null;
        $unchanged = $stored === null ? $options === null : ($options !== null && AccountabilityRules::ordered($stored) === $options);

        if ($unchanged) {
            return;
        }

        $this->audit->record($actor->id, self::ACTION, null, false, fn () => $this->settings->set($unitId, $options));
    }
}
