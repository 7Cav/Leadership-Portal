<?php

namespace App\Commands\Changelog;

use App\Authorization\ChangelogAccess;
use App\Models\User;
use App\Repositories\ChangelogRepository;
use App\Support\Audit\AuditEnvelope;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The changelog's Admin setting (AD-16): whether technical entries are available to everyone. While off, only the
 * changelog editors are sent them. Checked against {@see ChangelogAccess::mayManageSettings()} before anything is
 * written, so only an Admin signed in as themselves, not previewing, changes it. No reason is required.
 */
class ChangelogSettings
{
    public function __construct(
        private readonly ChangelogRepository $changelog,
        private readonly AuditEnvelope $audit,
        private readonly ChangelogAccess $access,
    ) {}

    public function setTechnicalEnabled(User $actor, bool $enabled): void
    {
        if (! $this->access->mayManageSettings($actor)) {
            throw new AuthorizationException('Only an Admin, signed in as themselves, may change the changelog settings.');
        }

        $this->audit->record(
            actorId: $actor->id,
            action: 'changelog.technical_setting_changed',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->changelog->setTechnicalEnabled($enabled),
        );
    }
}
