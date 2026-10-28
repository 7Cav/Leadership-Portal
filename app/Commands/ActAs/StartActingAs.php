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
 * An Admin or a leader starts acting as a person, or an Admin as a billet (#379, #432), recorded as `act_as.started`
 * against that user or unit (AD-16) with whoever starts as actor. A view-as preview they had open ends first (recorded
 * as stopped), since the two never run together. Switching straight to someone else records the old one as stopped.
 * Refused, storing and logging nothing, unless {@see ActAs} allows it: for an Admin acting as nobody, a person who
 * could sign in (an Admin without their Admin rights) or any unit that exists; for a leader, or an Admin acting as one,
 * only a serving billet holder strictly below them in their own write scope. Nobody who acts as a person starts
 * another without stopping first, except an Admin through a leader, who stays inside that leader's scope.
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
     * @param  User  $admin  who starts: the real signed-in user, an Admin or a leader
     * @return bool false when they may not act as that person or billet
     */
    public function handle(User $admin, ViewAsTarget $target): bool
    {
        if ($this->actAs->nested($admin)) {
            return false;
        }

        // Already acting as this target and it still holds: nothing to start, nothing to record.
        $via = $this->actAs->viaFor($admin);
        if ($via == $target) {
            return true;
        }

        $subject = $this->subjectOf($target);
        $allowed = match (true) {
            $subject instanceof User => $this->actAs->allows($admin, $subject, $via),
            $subject instanceof Unit => $this->actAs->allowsBillet($admin, $via),
            default => false,
        };
        if (! $allowed) {
            return false;
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
            mutation: function () use ($admin, $target, $subject, $via) {
                $this->actAs->start($admin, $target, $via);

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
