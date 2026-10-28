<?php

namespace App\Commands\Templates;

use App\Models\TemplateVersion;
use App\Models\User;
use App\Repositories\TemplateUnchangedException;
use App\Repositories\TemplateVersionRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Make an existing version of a template the active one again (Story 3.5, #375, AD-17), for example to roll back a bad
 * edit. Nothing about the version changes: only its template's active pointer moves. Allowed to whoever may manage the
 * template ({@see TemplateAuthority}), checked here. Routed through {@see AuditEnvelope} with a reason always required
 * (AD-16).
 *
 * Activating the version that is already active is a no-op that is not logged, so a double-click never records a
 * second activation.
 */
class ActivateTemplateVersion
{
    public function __construct(
        private readonly TemplateVersionRepository $versions,
        private readonly TemplateAuthority $authority,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws TemplateForbiddenException when the actor may not manage the version's template.
     */
    public function handle(User $actor, TemplateVersion $version, ?string $reason): TemplateVersion
    {
        $template = $this->versions->findTemplate($version->template_id);

        if ($template === null || ! $this->authority->manages($actor, $template)) {
            throw $template?->isRegimentDefault() ? TemplateForbiddenException::regimentDefault() : TemplateForbiddenException::company();
        }

        if ($template->active_version_id === $version->id) {
            return $version;
        }

        // As in AuthorTemplateVersion: the check above is the cheap path; the repository re-checks under its lock, so
        // a double-click that raced past it records nothing.
        try {
            return $this->audit->record(
                actorId: $actor->id,
                action: 'template_version.activated',
                reason: $reason,
                reasonRequired: true,
                mutation: fn () => $this->versions->activate($version, $actor->id),
            );
        } catch (TemplateUnchangedException $unchanged) {
            return $unchanged->version();
        }
    }
}
