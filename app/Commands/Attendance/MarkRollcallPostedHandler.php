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
 * Marks a section's weekly rollcall as posted (3.10): finds or creates the week's practice row and
 * stamps it with now and the actor, as one audited action (AD-16). Marking again — after edits
 * that needed a repost — overwrites the stamp: the latest post wins, and each mark is its own
 * audit entry. Refuses a section outside the actor's scope (AD-8) and a unit that is not a section.
 *
 * A week whose section report has been really sent is locked for this too (AD-12), as it is for
 * every other rollcall write: marking it posted is refused with `RollcallWeekLockedException`,
 * checked first inside the audited transaction under the report's shared lock, so a refused mark
 * leaves no stamp and no audit entry.
 */
class MarkRollcallPostedHandler
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
     * @throws InvalidAttendanceMarkException when the unit is not a section, or the week is before `WeekOf::floor()`.
     * @throws RollcallWeekLockedException when the section's report for the week has been sent.
     */
    public function handle(User $actor, MarkRollcallPosted $command): SectionPractice
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
            action: 'rollcall.posted',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($command, $weekStart, $actor) {
                if ($this->reports->isSentFor($command->sectionId, $weekStart)) {
                    throw RollcallWeekLockedException::for($command->sectionId, $weekStart);
                }

                return $this->practices->markRollcallPosted(
                    $this->practices->findOrCreate($command->sectionId, $weekStart),
                    $actor->id,
                );
            },
        );
    }
}
