<?php

namespace App\Commands\ActAs;

use App\Authorization\ActAs;
use App\Authorization\ViewAs;
use App\Authorization\ViewAsKind;
use App\Commands\ViewAs\ExitViewAs;
use App\Models\User;
use App\Repositories\UnitRepository;
use App\Repositories\UserRepository;
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;

/**
 * An Admin stops acting as a person or billet (#379), recorded as `act_as.stopped` against that user or unit (AD-16)
 * with the Admin as actor. Stopping when nothing is stored only clears the session: there is nothing to record. A
 * person or unit since deleted is cleared without an entry, since the log needs a row to point at.
 *
 * A view-as preview opened while acting ends first, recorded as done while acting, so it never comes back the next
 * time. The stop itself is the Admin's own: from then on nothing on this request is recorded as done while acting.
 */
class StopActingAs
{
    public function __construct(
        private readonly ActAs $actAs,
        private readonly UserRepository $users,
        private readonly UnitRepository $units,
        private readonly AuditEnvelope $audit,
        private readonly ViewAs $viewAs,
        private readonly ExitViewAs $exitViewAs,
        private readonly ActingFor $acting,
    ) {}

    public function handle(User $admin): void
    {
        $stored = $this->actAs->storedFor($admin);
        $subject = match ($stored?->kind) {
            ViewAsKind::User => $this->users->find($stored->id),
            ViewAsKind::Unit => $this->units->find($stored->id),
            ViewAsKind::Trooper => null,
            null => null,
        };

        if ($subject === null) {
            $this->actAs->stop();

            return;
        }

        // The preview belongs to whoever the request ran as: the person, or the Admin's own id holding a billet.
        $previewer = $subject instanceof User ? $subject : $admin;
        if ($this->viewAs->targetFor($previewer) !== null) {
            $this->exitViewAs->handle($previewer);
        }

        $this->acting->clear();

        $this->audit->record(
            actorId: $admin->id,
            action: 'act_as.stopped',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($subject) {
                $this->actAs->stop();

                return $subject;
            },
        );
    }
}
