<?php

namespace App\Commands\Templates;

use App\Commands\Units\CreateUnit;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Repositories\TemplateUnchangedException;
use App\Repositories\TemplateVersionRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * An Admin makes an existing version of a company's template the active one again (Story 3.5, AD-17), for example to
 * roll back a bad edit. Nothing about the version changes: only the active pointer moves. Admin-only, enforced by the
 * controller (AD-6) as for {@see CreateUnit}. Routed through {@see AuditEnvelope} with a reason always required (AD-16).
 *
 * Activating the version that is already active is a no-op that is not logged, so a double-click never records a
 * second activation.
 */
class ActivateTemplateVersion
{
    public function __construct(
        private readonly TemplateVersionRepository $versions,
        private readonly AuditEnvelope $audit,
    ) {}

    public function handle(User $actor, TemplateVersion $version, ?string $reason): TemplateVersion
    {
        if ($this->versions->activeFor($version->company_id, $version->artifact_type)?->id === $version->id) {
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
            return $unchanged->version;
        }
    }
}
