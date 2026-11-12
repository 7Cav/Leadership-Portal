<?php

namespace App\Commands\WeeklyReports;

use App\Models\Unit;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\PlatoonTrainingTopicRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportAlreadySentException;
use App\Repositories\WeeklyReportRepository;
use App\Support\Time\WeekOf;

/**
 * Saves the notes and unaccounted reasons of a section's draft report from the roll call (#703), as a draft save
 * ({@see SaveReportDraftHandler}, which checks and audits it) that keeps the training subject: the subject the report
 * shows is sent back as it is, so the section's own entry, or its following of the platoon's topic, does not change.
 */
class SaveReportNotesHandler
{
    public function __construct(
        private readonly UnitRepository $units,
        private readonly WeeklyReportRepository $reports,
        private readonly PlatoonTrainingTopicRepository $topics,
        private readonly SaveReportDraftHandler $drafts,
    ) {}

    /**
     * @throws SendWeeklyReportForbiddenException when the unit is outside the actor's write scope, or does not exist.
     * @throws InvalidReportContentException when a reason is not the section's to record, or the week is before the floor.
     * @throws WeeklyReportAlreadySentException when the section's report for the week is sent.
     */
    public function handle(User $actor, SaveReportNotes $command): WeeklyReport
    {
        $section = $this->units->find($command->sectionId);
        $weekStart = WeekOf::start($command->week);
        $topic = $section instanceof Unit && $section->parent_id !== null ? $this->topics->forSection($section, $weekStart) : null;

        return $this->drafts->handle($actor, new SaveReportDraft(
            $command->sectionId,
            $weekStart->toDateTimeImmutable(),
            $command->notes,
            SectionTrainingSubject::effective($topic, $this->reports->find($command->sectionId, $weekStart)?->training_subject),
            $command->unaccounted,
        ));
    }
}
