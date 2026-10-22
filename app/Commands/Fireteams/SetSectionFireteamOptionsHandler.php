<?php

namespace App\Commands\Fireteams;

use App\Authorization\ScopeResolver;
use App\Models\FireteamSetting;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\FireteamRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Records a section's own fireteam display choices as one audited action (`fireteam.options_set`, AD-16). Only a leader
 * who commands the section may ({@see ScopeResolver::commandsUnit()}); a staff grant does not.
 *
 * A choice the owning company enforces is not the section's to make: setting it to anything but the company's value is
 * refused, so the section's stored choice never silently disagrees with what its post shows. Choices the company leaves
 * open are written as given.
 */
class SetSectionFireteamOptionsHandler
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly FireteamRepository $fireteams,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws FireteamForbiddenException when the actor does not command the section (or it does not exist).
     * @throws InvalidFireteamException when the unit is not a section, or a choice is enforced by its company.
     */
    public function handle(User $actor, SetSectionFireteamOptions $command): FireteamSetting
    {
        $section = $this->units->find($command->sectionId);

        if ($section === null || ! $this->scope->commandsUnit($actor, $section)) {
            throw new FireteamForbiddenException($command->sectionId);
        }

        if ($section->type !== UnitType::Section) {
            throw InvalidFireteamException::notASection($section->id);
        }

        $company = $this->units->owningCompany($section);
        $policy = $company === null ? null : $this->fireteams->settingFor($company->id);

        if ($command->show !== null && $policy?->show_fireteams !== null && $command->show !== $policy->show_fireteams) {
            throw InvalidFireteamException::setByCompany('whether fireteam leaders are shown');
        }

        if ($command->order !== null && $policy?->fireteam_order !== null && $command->order !== $policy->fireteam_order) {
            throw InvalidFireteamException::setByCompany('where fireteam leaders are listed');
        }

        $values = array_filter(
            ['show_fireteams' => $command->show, 'fireteam_order' => $command->order],
            fn ($value) => $value !== null,
        );

        return $this->audit->record(
            actorId: $actor->id,
            action: 'fireteam.options_set',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->fireteams->updateSetting($section->id, $values),
        );
    }
}
