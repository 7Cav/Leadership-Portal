<?php

namespace App\Commands\Templates;

use App\Models\CompanyTemplatePolicy;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TemplateUnchangedException;
use App\Repositories\TemplateVersionRepository;
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;

/**
 * Battalion command switches a company's own template editing on or off (#376, AD-17). With it off, the company's
 * command can no longer create, edit, activate or switch the company's templates; the battalion command and Admins
 * manage them instead, exactly as the company could. Everything else in the company's rollcall (marking, confirming,
 * posting, fireteams, reports, a section's pick of template) stays with the company. On by default.
 *
 * Only the command above the company and Admins may ({@see TemplateAuthority::commandsAbove()}). Audited
 * (`template_policy.set`, AD-16); a switch that changes nothing is not logged.
 */
class SetCompanyTemplatePolicy
{
    public function __construct(
        private readonly TemplateVersionRepository $versions,
        private readonly TemplateAuthority $authority,
        private readonly AuditEnvelope $audit,
        private readonly ActingFor $acting,
    ) {}

    /**
     * @throws TemplateForbiddenException when the actor does not command the unit above the company.
     * @throws InvalidTemplateException when the unit is not a company.
     */
    public function handle(User $actor, Unit $company, bool $companyEdits): ?CompanyTemplatePolicy
    {
        if (! $this->authority->commandsAbove($actor, $company)) {
            throw TemplateForbiddenException::policy();
        }

        if ($company->type !== UnitType::Company) {
            throw InvalidTemplateException::notACompany($company);
        }

        try {
            return $this->audit->record(
                actorId: $actor->id,
                action: 'template_policy.set',
                reason: null,
                reasonRequired: false,
                mutation: fn () => $this->versions->setCompanyEditsTemplates($company, $companyEdits, $actor->id, $this->acting->adminActingAs($actor->id), $this->acting->billetOf($actor->id)),
            );
        } catch (TemplateUnchangedException $unchanged) {
            return $unchanged->subject instanceof CompanyTemplatePolicy ? $unchanged->subject : null;
        }
    }
}
