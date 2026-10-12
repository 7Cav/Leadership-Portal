<?php

namespace App\Commands\Attendance;

use App\Authorization\ScopeResolver;
use App\Models\SectionPractice;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekOf;

/**
 * Confirms a section's practice (7.8): finds or creates the week's practice row and stamps it with
 * now and the actor, as one audited action (AD-16). Works for a captured practice and a purely
 * manual one alike. Confirming again re-stamps; each confirmation is its own audit entry. Refuses a
 * section outside the actor's scope (AD-8) and a unit that is not a section.
 *
 * Not the same as "signed" (a trooper signing the rollcall thread, 3.7), "posted" (the leader
 * posting the BBCode to the forum, 3.10) or the weekly report's send freeze (5.6): this says the
 * leader reviewed the practice marks. A later hand edit of a mark clears it (see
 * {@see MarkAttendanceHandler}), and capture pre-marks leave a confirmed practice alone.
 */
class ConfirmSectionPracticeHandler
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly SectionPracticeRepository $practices,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws ConfirmSectionPracticeForbiddenException when the section is outside the actor's scope.
     * @throws InvalidAttendanceMarkException when the unit is not a section.
     */
    public function handle(User $actor, ConfirmSectionPractice $command): SectionPractice
    {
        if (! $this->scope->resolve($actor)->contains($command->sectionId)) {
            throw new ConfirmSectionPracticeForbiddenException($command->sectionId);
        }

        if ($this->units->find($command->sectionId)?->type !== UnitType::Section) {
            throw InvalidAttendanceMarkException::notASection($command->sectionId);
        }

        $weekStart = WeekOf::start($command->week);

        return $this->audit->record(
            actorId: $actor->id,
            action: 'practice.confirmed',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->practices->confirm(
                $this->practices->findOrCreate($command->sectionId, $weekStart),
                $actor->id,
            ),
        );
    }
}
