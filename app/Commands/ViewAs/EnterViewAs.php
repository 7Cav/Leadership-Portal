<?php

namespace App\Commands\ViewAs;

use App\Authorization\ScopeResolver;
use App\Authorization\ViewAs;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Models\Unit;
use App\Models\User;
use App\Repositories\UnitRepository;
use App\Repositories\UserRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Start viewing the portal as a unit or a person (Story 1.13), recorded as `view_as.started` against the unit or user
 * being viewed as (AD-16), so a preview shows in the audit log even though nothing can be written during one. Whether
 * the target is allowed is {@see ScopeResolver::evaluate()}'s call; a refused target stores nothing and logs nothing.
 * Switching straight to another target first records the old one as stopped.
 */
class EnterViewAs
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly ViewAs $viewAs,
        private readonly UnitRepository $units,
        private readonly UserRepository $users,
        private readonly AuditEnvelope $audit,
        private readonly ExitViewAs $exit,
    ) {}

    /**
     * @return bool false when the target is outside the viewer's real authority
     */
    public function handle(User $viewer, ViewAsTarget $target): bool
    {
        if ($this->scope->evaluate($viewer, $target) === null) {
            return false;
        }

        $subject = $this->subjectOf($target);

        if ($subject === null) {
            return false;
        }

        $current = $this->viewAs->targetFor($viewer);

        if ($current == $target) {
            return true;
        }

        if ($current !== null) {
            $this->exit->handle($viewer);
        }

        $this->audit->record(
            actorId: $viewer->id,
            action: 'view_as.started',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($viewer, $target, $subject) {
                $this->viewAs->enter($viewer, $target);

                return $subject;
            },
        );

        return true;
    }

    private function subjectOf(ViewAsTarget $target): Unit|User|null
    {
        return $target->kind === ViewAsKind::User ? $this->users->find($target->id) : $this->units->find($target->id);
    }
}
