<?php

namespace App\Commands\ViewAs;

use App\Authorization\ViewAs;
use App\Authorization\ViewAsKind;
use App\Models\User;
use App\Repositories\UnitRepository;
use App\Repositories\UserRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Back to the viewer's own scope (Story 1.13), recorded as `view_as.stopped` against the unit or user that was being
 * viewed as (AD-16). Leaving when no preview is stored only clears the session: there is nothing to record. A target
 * that has since been deleted is cleared without an entry, since the log needs a row to point at.
 */
class ExitViewAs
{
    public function __construct(
        private readonly ViewAs $viewAs,
        private readonly UnitRepository $units,
        private readonly UserRepository $users,
        private readonly AuditEnvelope $audit,
    ) {}

    public function handle(User $viewer): void
    {
        $target = $this->viewAs->targetFor($viewer);
        $subject = match ($target?->kind) {
            ViewAsKind::User => $this->users->find($target->id),
            ViewAsKind::Unit => $this->units->find($target->id),
            null => null,
        };

        if ($subject === null) {
            $this->viewAs->exit();

            return;
        }

        $this->audit->record(
            actorId: $viewer->id,
            action: 'view_as.stopped',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($subject) {
                $this->viewAs->exit();

                return $subject;
            },
        );
    }
}
