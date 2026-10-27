<?php

namespace App\Commands\ActAs;

use App\Authorization\ActAs;
use App\Authorization\ViewAs;
use App\Commands\ViewAs\ExitViewAs;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * An Admin stops acting as someone (#379), recorded as `act_as.stopped` against that user (AD-16) with the Admin as
 * actor. Stopping when nobody is stored only clears the session: there is nothing to record. A person since deleted
 * is cleared without an entry, since the log needs a row to point at. A view-as preview opened while acting (bound to
 * the person) ends first, recorded as the Admin acting for them, so it never comes back the next time.
 */
class StopActingAs
{
    public function __construct(
        private readonly ActAs $actAs,
        private readonly UserRepository $users,
        private readonly AuditEnvelope $audit,
        private readonly ViewAs $viewAs,
        private readonly ExitViewAs $exitViewAs,
    ) {}

    public function handle(User $admin): void
    {
        $id = $this->actAs->storedFor($admin);
        $user = $id === null ? null : $this->users->find($id);

        if ($user === null) {
            $this->actAs->stop();

            return;
        }

        if ($this->viewAs->targetFor($user) !== null) {
            $this->exitViewAs->handle($user);
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
