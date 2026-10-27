<?php

namespace App\Commands\ActAs;

use App\Authorization\ActAs;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * An Admin stops acting as someone (#379), recorded as `act_as.stopped` against that user (AD-16) with the Admin as
 * actor. Stopping when nobody is stored only clears the session: there is nothing to record. A person since deleted
 * is cleared without an entry, since the log needs a row to point at.
 */
class StopActingAs
{
    public function __construct(
        private readonly ActAs $actAs,
        private readonly UserRepository $users,
        private readonly AuditEnvelope $audit,
    ) {}

    public function handle(User $admin): void
    {
        $id = $this->actAs->storedFor($admin);
        $user = $id === null ? null : $this->users->find($id);

        if ($user === null) {
            $this->actAs->stop();

            return;
        }

        $this->audit->record(
            actorId: $admin->id,
            action: 'act_as.stopped',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($user) {
                $this->actAs->stop();

                return $user;
            },
        );
    }
}
