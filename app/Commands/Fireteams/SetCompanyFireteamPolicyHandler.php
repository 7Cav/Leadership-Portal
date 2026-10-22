<?php

namespace App\Commands\Fireteams;

use App\Authorization\ScopeResolver;
use App\Models\FireteamOrder;
use App\Models\FireteamSetting;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\FireteamRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Records a company's fireteam policy as one audited action (`fireteam.policy_set`, AD-16). Only the company's command
 * team, the battalion or regimental command above it, or an Admin may ({@see ScopeResolver::commandsUnit()}); a staff
 * grant does not, and neither does a section or platoon leader beneath it.
 *
 * The policy shows or hides FTLs on every section (`show`; shown only prints something where a section has an FTL),
 * keeps FTLs above the rest (`order` LeadersFirst), or leaves either to the sections (null). Forcing the by-fireteam
 * order is refused. It also says whether the sections' SL and ASL may lead a fireteam (`slAslLead`), which only a
 * company sets. The sections' own stored choices, and fireteams an SL or ASL led, are left as they are, so lifting the
 * policy brings them back.
 */
class SetCompanyFireteamPolicyHandler
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly FireteamRepository $fireteams,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws FireteamForbiddenException when the actor does not command the company (or it does not exist).
     * @throws InvalidFireteamException when the unit is not a company, or the policy would force the by-fireteam order.
     */
    public function handle(User $actor, SetCompanyFireteamPolicy $command): FireteamSetting
    {
        $company = $this->units->find($command->companyId);

        if ($company === null || ! $this->scope->commandsUnit($actor, $company)) {
            throw new FireteamForbiddenException($command->companyId);
        }

        if ($company->type !== UnitType::Company) {
            throw InvalidFireteamException::notACompany($company->id);
        }

        if ($command->order === FireteamOrder::ByFireteam) {
            throw InvalidFireteamException::notAPolicy('the fireteam order');
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'fireteam.policy_set',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->fireteams->updateSetting($company->id, [
                'show_fireteams' => $command->show,
                'fireteam_order' => $command->order,
                'sl_asl_lead_fireteams' => $command->slAslLead,
            ]),
        );
    }
}
