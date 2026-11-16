<?php

namespace App\Commands\Attendance;

use App\Authorization\ScopeResolver;
use App\Models\SectionPractice;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekOf;

/**
 * Puts back the posted stamp the last "mark posted" replaced (#766), as one audited action (`rollcall.post_undone`,
 * AD-16): unticking "I edited the forum post" after a misclick, so the close step and command see the post as not
 * edited again. Gated like {@see MarkRollcallPostedHandler}: the section in the actor's write scope (AD-8), a section,
 * not before `WeekOf::floor()`, and refused once the week's report has been sent (AD-12). The undo is used up, so a
 * second one, or one with no "mark posted" behind it, is refused with `nothingToUndo`.
 */
class UndoRollcallPostedHandler
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly SectionPracticeRepository $practices,
        private readonly WeeklyReportRepository $reports,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws MarkRollcallPostedForbiddenException when the section is outside the actor's scope.
     * @throws InvalidAttendanceMarkException when the unit is not a section, the week is before `WeekOf::floor()`, or
     *                                        there is no "mark posted" to undo.
     * @throws RollcallWeekLockedException when the section's report for the week has been sent.
     */
    public function handle(User $actor, UndoRollcallPosted $command): SectionPractice
    {
        if (! $this->scope->writeScope($actor)->contains($command->sectionId)) {
            throw new MarkRollcallPostedForbiddenException($command->sectionId);
        }

        if ($this->units->find($command->sectionId)?->type !== UnitType::Section) {
            throw InvalidAttendanceMarkException::notASection($command->sectionId);
        }

        $weekStart = WeekOf::start($command->week);

        if (WeekOf::isBeforeFloor($weekStart)) {
            throw InvalidAttendanceMarkException::weekBeforeFloor($weekStart);
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'rollcall.post_undone',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($command, $weekStart) {
                if ($this->reports->isSentFor($command->sectionId, $weekStart)) {
                    throw RollcallWeekLockedException::for($command->sectionId, $weekStart);
                }

                // Under its row lock, so two undos at once put the stamp back once.
                $found = $this->practices->find($command->sectionId, $weekStart);
                $practice = $found === null ? null : $this->practices->lockById($found->id);

                return ($practice === null ? null : $this->practices->undoRollcallPosted($practice))
                    ?? throw InvalidAttendanceMarkException::nothingToUndo();
            },
        );
    }
}
