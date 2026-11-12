<?php

namespace App\Commands\Attendance;

use App\Authorization\ScopeResolver;
use App\Commands\Practices\ChangePracticeWeek;
use App\Commands\Practices\InvalidPracticeSlotException;
use App\Commands\Practices\PracticeSchedule;
use App\Commands\WeeklyReports\InvalidReportContentException;
use App\Commands\WeeklyReports\SetSectionTrainingSubject;
use App\Commands\WeeklyReports\SetSectionTrainingSubjectHandler;
use App\Commands\WeeklyReports\TrainingSubjectEnforcedException;
use App\Models\SectionPractice;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\RollcallFlow;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportAlreadySentException;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;

/**
 * Creates a section's roll call for a week on the new flow (#703): the first step of the weekly cycle. The training
 * date and topic go where they already live, so nothing is copied: a changed date moves this week's practice only
 * ({@see ChangePracticeWeek}, the slot stays), "practice will not take place" cancels this week's practice, and the
 * topic is the weekly report's training subject ({@see SetSectionTrainingSubjectHandler}). Then the week's practice row
 * is stamped as created. All of it is one audited action (`rollcall.created`, AD-16); the date and topic writes keep
 * their own audit entries inside it, and a refusal of either leaves nothing written.
 *
 * Creating again keeps the first stamp, and still applies a changed date or topic. Refused: a section outside the
 * actor's write scope (AD-8), a unit that is not a section, a week before `WeekOf::floor()`, a week on the three-step
 * flow, and a week whose report was sent (AD-12).
 */
class CreateRollcallHandler
{
    public const ACTION = 'rollcall.created';

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly SectionPracticeRepository $practices,
        private readonly WeeklyReportRepository $reports,
        private readonly PracticeSchedule $schedule,
        private readonly ChangePracticeWeek $changeWeek,
        private readonly SetSectionTrainingSubjectHandler $subjects,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws CreateRollcallForbiddenException when the section is outside the actor's write scope.
     * @throws InvalidAttendanceMarkException when the unit is not a section, or the week is before `WeekOf::floor()`.
     * @throws CreateRollcallRefusedException when the week follows the three-step flow.
     * @throws RollcallWeekLockedException when the section's report for the week has been sent.
     * @throws InvalidPracticeSlotException when the date cannot be changed: no slot, already captured, or outside the week.
     * @throws TrainingSubjectEnforcedException when the platoon enforces a different topic.
     * @throws InvalidReportContentException|WeeklyReportAlreadySentException from the topic write, as {@see SetSectionTrainingSubjectHandler}.
     */
    public function handle(User $actor, CreateRollcall $command): SectionPractice
    {
        if (! $this->scope->writeScope($actor)->contains($command->sectionId)) {
            throw new CreateRollcallForbiddenException($command->sectionId);
        }

        if ($this->units->find($command->sectionId)?->type !== UnitType::Section) {
            throw InvalidAttendanceMarkException::notASection($command->sectionId);
        }

        $weekStart = WeekOf::start($command->week);

        if (WeekOf::isBeforeFloor($weekStart)) {
            throw InvalidAttendanceMarkException::weekBeforeFloor($weekStart);
        }

        if (RollcallFlow::of($this->practices->find($command->sectionId, $weekStart), $weekStart) !== RollcallFlow::V2) {
            throw CreateRollcallRefusedException::notOnTheNewFlow();
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: self::ACTION,
            reason: null,
            reasonRequired: false,
            mutation: function () use ($actor, $command, $weekStart) {
                // A topic writes the report's draft: its row is locked for update first, so a report send of the same
                // week waits for this create rather than deadlocking on an upgraded shared lock (AD-12).
                if ($command->subject !== null) {
                    try {
                        $this->reports->lockForSend($command->sectionId, $weekStart);
                    } catch (WeeklyReportAlreadySentException) {
                        throw RollcallWeekLockedException::for($command->sectionId, $weekStart);
                    }
                } elseif ($this->reports->isSentFor($command->sectionId, $weekStart)) {
                    throw RollcallWeekLockedException::for($command->sectionId, $weekStart);
                }

                $this->applyDate($actor, $command, $weekStart);

                if ($command->subject !== null) {
                    $this->subjects->handle($actor, new SetSectionTrainingSubject($command->sectionId, $weekStart->toDateTimeImmutable(), $command->subject));
                }

                return $this->practices->markCreated($this->practices->findOrCreate($command->sectionId, $weekStart), $actor->id);
            },
        );
    }

    /**
     * Cancel this week's practice, or move it when the date differs from this week's start (the slot's, or this
     * week's own time). A date left as it is, or none, changes nothing, so a week without a practice can still be
     * created.
     */
    private function applyDate(User $actor, CreateRollcall $command, CarbonImmutable $weekStart): void
    {
        $week = $this->schedule->props($command->sectionId, $weekStart, null)['week'] ?? null;

        if ($command->cancelled) {
            if (! ($week['cancelled'] ?? false)) {
                $this->changeWeek->handle($actor, $command->sectionId, $weekStart, null, true);
            }

            return;
        }

        $current = isset($week['start']) ? CarbonImmutable::parse($week['start']) : null;

        if ($command->movedTo !== null && $current?->equalTo($command->movedTo) !== true) {
            $this->changeWeek->handle($actor, $command->sectionId, $weekStart, $command->movedTo, false);
        }
    }
}
