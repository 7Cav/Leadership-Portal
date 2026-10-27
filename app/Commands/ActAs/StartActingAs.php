<?php

namespace App\Commands\ActAs;

use App\Authorization\ActAs;
use App\Authorization\ViewAs;
use App\Commands\ViewAs\ExitViewAs;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * An Admin starts acting as another person (#379), recorded as `act_as.started` against that user (AD-16) with the
 * Admin as actor. A view-as preview the Admin had open ends first (recorded as stopped), since the two never run
 * together. Switching straight to another person records the old one as stopped. Refused, storing and logging
 * nothing, unless {@see ActAs::allows()}: a real Admin, not themselves, not another Admin, a person who could sign in.
 */
class StartActingAs
{
    public function __construct(
        private readonly ActAs $actAs,
        private readonly ViewAs $viewAs,
        private readonly UserRepository $users,
        private readonly AuditEnvelope $audit,
        private readonly ExitViewAs $exitViewAs,
        private readonly StopActingAs $stop,
    ) {}

    /**
     * @return bool false when the Admin may not act as that person
     */
    public function handle(User $admin, int $userId): bool
    {
        $user = $this->users->find($userId);
        if ($user === null || ! $this->actAs->allows($admin, $user)) {
            return false;
        }

        if ($this->actAs->storedFor($admin) === $user->id) {
            return true;
        }

        $this->stop->handle($admin);
        if ($this->viewAs->targetFor($admin) !== null) {
            $this->exitViewAs->handle($admin);
        }

        $this->audit->record(
            actorId: $admin->id,
            action: 'act_as.started',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($admin, $user) {
                $this->actAs->start($admin, $user);

                return $user;
            },
        );

        return true;
    }
}
