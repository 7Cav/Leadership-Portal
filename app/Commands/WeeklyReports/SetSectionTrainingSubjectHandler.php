<?php

namespace App\Commands\WeeklyReports;

use App\Authorization\ScopeResolver;
use App\Kpi\ReportKpisUnavailableException;
use App\Kpi\WeeklyReportKpis;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\PlatoonTrainingTopicRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportAlreadySentException;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekOf;

/**
 * Changes the training subject of a section's draft report (#703), from the roll call, making the draft if there is
 * none: the draft's notes and unaccounted reasons stay as they were, so setting the topic on the roll call never undoes
 * work on the report page, and its KPI copy is refreshed as a draft save refreshes it. Audited through the envelope
 * without a reason, as a draft save is ({@see SaveReportDraftHandler}).
 *
 * Refused before anything is written: a unit outside the actor's write scope (AD-8) or not a section, a week before
 * `WeekOf::floor()`, a subject other than the one the platoon enforces ({@see SectionTrainingSubject::forDraft()}), and
 * a report already sent, which is frozen (AD-12).
 */
class SetSectionTrainingSubjectHandler
{
    public const ACTION = 'weekly_report.training_subject_set';

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly WeeklyReportRepository $reports,
        private readonly PlatoonTrainingTopicRepository $topics,
        private readonly WeeklyReportKpis $snapshots,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws SendWeeklyReportForbiddenException when the unit is outside the actor's write scope, or does not exist.
     * @throws InvalidReportContentException when the unit is not a section, or the week is before `WeekOf::floor()`.
     * @throws TrainingSubjectEnforcedException when the platoon enforces a different subject.
     * @throws WeeklyReportAlreadySentException when the section's report for the week is sent.
     */
    public function handle(User $actor, SetSectionTrainingSubject $command): WeeklyReport
    {
        $section = $this->scope->writeScope($actor)->contains($command->sectionId) ? $this->units->find($command->sectionId) : null;

        if ($section === null) {
            throw new SendWeeklyReportForbiddenException($command->sectionId);
        }

        if ($section->type !== UnitType::Section) {
            throw InvalidReportContentException::notASection($section->id);
        }

        $weekStart = WeekOf::start($command->week);

        if (WeekOf::isBeforeFloor($weekStart)) {
            throw InvalidReportContentException::weekBeforeFloor($weekStart);
        }

        try {
            $snapshot = $this->snapshots->snapshot($section, $weekStart)->toArray();
        } catch (ReportKpisUnavailableException) {
            // The KPI copy is informational only: the draft keeps the one it last saved, and the subject still saves.
            $snapshot = null;
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: self::ACTION,
            reason: null,
            reasonRequired: false,
            mutation: function () use ($section, $weekStart, $command, $snapshot) {
                // The row is locked for update before the draft is read, so a report-page autosave or a send of the
                // same week waits for this write instead of being overwritten by it, and no shared lock is upgraded
                // (AD-12). A sent report is refused here.
                $this->reports->lockForSend($section->id, $weekStart);
                $stored = $this->reports->find($section->id, $weekStart);

                $subject = trim((string) $command->subject);
                $subject = SectionTrainingSubject::forDraft(
                    $section->id,
                    $this->topics->forSection($section, $weekStart),
                    $subject === '' ? null : $subject,
                    $stored?->training_subject,
                );

                return $this->reports->saveDraft(
                    $section->id,
                    $weekStart,
                    $snapshot ?? $stored->kpis ?? [],
                    $stored?->notes,
                    $subject,
                    $stored->unaccounted ?? [],
                );
            },
        );
    }
}
