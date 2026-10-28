<?php

namespace App\Commands\ActAs;

use App\Authorization\ActAs;
use App\Authorization\ViewAs;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Commands\ViewAs\ExitViewAs;
use App\Models\Unit;
use App\Models\User;
use App\Repositories\UnitRepository;
use App\Repositories\UserRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * An Admin starts acting as a person or a billet (#379), recorded as `act_as.started` against that user or unit
 * (AD-16) with the Admin as actor. A view-as preview the Admin had open ends first (recorded as stopped), since the two
 * never run together. Switching straight to someone else records the old one as stopped. Refused, storing and logging
 * nothing, unless {@see ActAs} allows it: a real Admin; for a person, not themselves, someone who could sign in (an
 * Admin without their Admin rights); for a billet, any unit that exists.
 */
class StartActingAs
{
    public function __construct(
        private readonly ActAs $actAs,
        private readonly ViewAs $viewAs,
        private readonly UserRepository $users,
        private readonly UnitRepository $units,
        private readonly AuditEnvelope $audit,
        private readonly ExitViewAs $exitViewAs,
        private readonly StopActingAs $stop,
    ) {}

    /**
     * @return bool false when the Admin may not act as that person or billet
     */
    public function handle(User $admin, ViewAsTarget $target): bool
    {
        $subject = $this->subjectOf($target);
        $allowed = match (true) {
            $subject instanceof User => $this->actAs->allows($admin, $subject),
            $subject instanceof Unit => $this->actAs->allowsBillet($admin),
            default => false,
        };
        if (! $allowed) {
            return false;
        }

        if ($this->actAs->storedFor($admin) == $target) {
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
            mutation: function () use ($admin, $target, $subject) {
                $this->actAs->start($admin, $target);

                return $subject;
            },
        );

        return true;
    }

    private function subjectOf(ViewAsTarget $target): User|Unit|null
    {
        return $target->kind === ViewAsKind::User ? $this->users->find($target->id) : $this->units->find($target->id);
    }
}
