<?php

namespace App\Commands\Templates;

use App\Models\Template;
use App\Models\User;
use App\Repositories\TemplateUnchangedException;
use App\Repositories\TemplateVersionRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * A company's command switches one of its templates on or off for its sections' rollcalls (#375, AD-17). A section
 * leader picks only from the switched-on ones; a section whose pick is switched off falls back to the company's active
 * template, and a company with nothing switched on to the regiment default, never a blank post. The regiment default
 * is always on. Only whoever commands the company may ({@see TemplateAuthority}). Audited (AD-16); switching a template
 * the way it already is changes nothing and is not logged.
 */
class SwitchTemplate
{
    public function __construct(
        private readonly TemplateVersionRepository $versions,
        private readonly TemplateAuthority $authority,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws TemplateForbiddenException when the actor does not command the template's company.
     * @throws InvalidTemplateException when the template is the regiment default.
     */
    public function handle(User $actor, Template $template, bool $enabled): Template
    {
        if ($template->isRegimentDefault()) {
            throw InvalidTemplateException::regimentDefaultAlwaysOn();
        }

        if (! $this->authority->manages($actor, $template)) {
            throw $this->authority->refusal($actor, $template);
        }

        if ($template->enabled === $enabled) {
            return $template;
        }

        try {
            return $this->audit->record(
                actorId: $actor->id,
                action: $enabled ? 'template.switched_on' : 'template.switched_off',
                reason: null,
                reasonRequired: false,
                mutation: fn () => $this->versions->setEnabled($template, $enabled),
            );
        } catch (TemplateUnchangedException) {
            return $template;
        }
    }
}
