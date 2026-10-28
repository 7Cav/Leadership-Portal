<?php

namespace App\Commands\Templates;

use App\Models\Template;
use App\Models\User;
use App\Repositories\TemplateUnchangedException;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * A company's command makes one of its templates the company's active one (#375, AD-17): the template every section
 * that has not picked its own renders from, while it is switched on. Only one of the company's own templates, and only
 * by whoever commands the company ({@see TemplateAuthority}). Audited (AD-16); choosing the template that is active
 * already changes nothing and is not logged.
 */
class SetCompanyTemplate
{
    public function __construct(
        private readonly TemplateVersionRepository $versions,
        private readonly UnitRepository $units,
        private readonly TemplateAuthority $authority,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws TemplateForbiddenException when the actor does not command the template's company.
     * @throws InvalidTemplateException when the template is the regiment default.
     */
    public function handle(User $actor, Template $template): void
    {
        if ($template->isRegimentDefault()) {
            throw InvalidTemplateException::notThisCompanys();
        }

        if (! $this->authority->manages($actor, $template)) {
            throw TemplateForbiddenException::company();
        }

        $company = $this->units->find($template->company_id);

        try {
            $this->audit->record(
                actorId: $actor->id,
                action: 'template.company_active_set',
                reason: null,
                reasonRequired: false,
                mutation: fn () => $this->versions->choose($company, $template, $actor->id),
            );
        } catch (TemplateUnchangedException) {
            // It is the company's active template already: nothing to record.
        }
    }
}
