<?php

namespace App\Commands\WeeklyReports;

use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\WeeklyReportAlreadySentException;
use App\Support\Time\WeekOf;

/**
 * Saves the notes and unaccounted reasons of a section's draft report from the roll call (#703), as a draft save
 * ({@see SaveReportDraftHandler}, which checks and audits it) that keeps the training subject as it is stored, read
 * under the row's lock: the section's own entry, or its following of the platoon's topic, does not change, and a topic
 * saved at the same moment is not undone.
 */
class SaveReportNotesHandler
{
    public function __construct(private readonly SaveReportDraftHandler $drafts) {}

    /**
     * @throws SendWeeklyReportForbiddenException when the unit is outside the actor's write scope, or does not exist.
     * @throws InvalidReportContentException when a reason is not the section's to record, or the week is before the floor.
     * @throws WeeklyReportAlreadySentException when the section's report for the week is sent.
     */
    public function handle(User $actor, SaveReportNotes $command): WeeklyReport
    {
        return $this->drafts->handle($actor, new SaveReportDraft(
            $command->sectionId,
            WeekOf::start($command->week)->toDateTimeImmutable(),
            $command->notes,
            null,
            $command->unaccounted,
            keepSubject: true,
        ));
    }
}
