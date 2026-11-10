<?php

namespace App\Commands\Tutorial;

use App\Models\User;
use App\Support\Audit\AuditEnvelope;

/**
 * Notes in the real audit log that someone started, started over or left the hands-on tutorial, against their own user.
 * Everything they do inside it stays in their practice copy, so this is the only trace of it in the real portal, which
 * helps when someone reports a bug from a tutorial page. Called outside the sandbox (TutorialController).
 */
class RecordTutorialUse
{
    public const STARTED = 'tutorial.started';

    public const RESTARTED = 'tutorial.restarted';

    public const EXITED = 'tutorial.exited';

    public function __construct(private readonly AuditEnvelope $audit) {}

    /** `$action` is one of the constants above. */
    public function handle(User $user, string $action): void
    {
        $this->audit->record(
            actorId: $user->id,
            action: $action,
            reason: null,
            reasonRequired: false,
            mutation: fn () => $user,
        );
    }
}
