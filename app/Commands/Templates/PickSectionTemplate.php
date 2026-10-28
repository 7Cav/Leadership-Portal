<?php

namespace App\Commands\Templates;

use App\Authorization\ScopeResolver;
use App\Models\ArtifactType;
use App\Models\Template;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TemplateUnchangedException;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitRepository;
use App\Rollcall\TemplateResolver;
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;

/**
 * A section's leader picks which template its rollcall post uses (#375, AD-17), from the ones its company switched on
 * and the regiment default ({@see TemplateResolver::optionsFor()}), or clears the pick (`$templateId` null) to go back to
 * the company's active template. The pick is the section's and persists until changed. Gated by the section's write
 * scope (AD-8): its leaders and the command above them, never a staff grant. Audited (AD-16); a pick that changes
 * nothing is not logged.
 */
class PickSectionTemplate
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly TemplateVersionRepository $versions,
        private readonly TemplateResolver $resolver,
        private readonly AuditEnvelope $audit,
        private readonly ActingFor $acting,
    ) {}

    /**
     * @throws TemplateForbiddenException when the section is outside the actor's write scope (or does not exist).
     * @throws InvalidTemplateException when the unit is not a section, or the template is not available to it.
     */
    public function handle(User $actor, int $sectionId, ?int $templateId, ArtifactType $type = ArtifactType::RollcallBbcode): void
    {
        // Scope first, so a unit outside it is refused whether or not it exists (AD-8).
        $section = $this->scope->writeScope($actor)->contains($sectionId) ? $this->units->find($sectionId) : null;

        if ($section === null) {
            throw TemplateForbiddenException::section();
        }

        if ($section->type !== UnitType::Section) {
            throw InvalidTemplateException::notASection($section);
        }

        // An id that names no template is refused like another company's: neither is a template this section may use.
        $template = $templateId === null ? null : $this->resolver->optionsFor($section, $type)->firstWhere('id', $templateId);

        if ($templateId !== null && ! $template instanceof Template) {
            throw InvalidTemplateException::notAvailable();
        }

        try {
            $this->audit->record(
                actorId: $actor->id,
                action: $template === null ? 'template.pick_cleared' : 'template.picked',
                reason: null,
                reasonRequired: false,
                mutation: fn () => $template === null
                    ? $this->versions->clearChoice($section, $type)
                    : $this->versions->choose($section, $template, $actor->id, $this->acting->adminActingAs($actor->id), $this->acting->billetOf($actor->id)),
            );
        } catch (TemplateUnchangedException) {
            // Already so: nothing to record.
        }
    }
}
