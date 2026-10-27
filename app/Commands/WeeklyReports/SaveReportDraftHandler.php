<?php

namespace App\Commands\WeeklyReports;

use App\Authorization\ScopeResolver;
use App\Kpi\ReportKpisUnavailableException;
use App\Kpi\WeeklyReportKpis;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\PlatoonTrainingTopicRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportAlreadySentException;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekOf;

/**
 * Saves a unit-week's draft report (5.5) through the audited envelope (AD-16; no reason — a draft
 * changes nothing anyone else reads as reported). The row's `kpis` is refreshed to the live
 * snapshot as a last saved copy only (left empty when the KPIs cannot be computed); a draft is always re-computed for display and the send
 * freezes its own (AD-12).
 *
 * Refused before anything is written: a unit outside the actor's scope (AD-8), content that is
 * not the section's to record, a training subject other than the one the platoon enforces for the
 * week, and a report that is already sent — a sent report is frozen. How the subject combines
 * with the platoon's topic is {@see SectionTrainingSubject::forDraft()}.
 */
class SaveReportDraftHandler
{
    public const ACTION = 'weekly_report.draft_saved';

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly WeeklyReportRepository $reports,
        private readonly WeeklyReportKpis $snapshots,
        private readonly AuditEnvelope $audit,
        private readonly PlatoonTrainingTopicRepository $topics,
    ) {}

    /**
     * @throws SendWeeklyReportForbiddenException when the unit is outside the actor's scope, or
     *                                            does not exist.
     * @throws InvalidReportContentException when the content is not the unit's to record, or the
     *                                       week is before `WeekOf::floor()`.
     * @throws TrainingSubjectEnforcedException when the platoon enforces a different subject.
     * @throws WeeklyReportAlreadySentException when the unit-week is already sent.
     */
    public function handle(User $actor, SaveReportDraft $command): WeeklyReport
    {
        $unit = $this->scope->writeScope($actor)->contains($command->unitId) ? $this->units->find($command->unitId) : null;

        // A group (a staff department, ELOA, Reservists, New Recruits) has no weekly report (2026-10-24).
        if ($unit === null || ! $unit->isLine()) {
            throw new SendWeeklyReportForbiddenException($command->unitId);
        }

        $weekStart = WeekOf::start($command->week);

        if (WeekOf::isBeforeFloor($weekStart)) {
            throw InvalidReportContentException::weekBeforeFloor($weekStart);
        }

        $content = ReportContent::checked($unit, $command->notes, $command->trainingSubject, $command->unaccounted, $this->troopers);
        $trainingSubject = SectionTrainingSubject::forDraft(
            $unit->id,
            $this->topics->forSection($unit, $weekStart),
            $content->trainingSubject,
            $this->reports->find($unit->id, $weekStart)?->training_subject,
        );
        try {
            $kpis = $this->snapshots->snapshot($unit, $weekStart)->toArray();
        } catch (ReportKpisUnavailableException) {
            // The copy is informational only; the leader's notes must still save. The send keeps
            // refusing until the numbers can be computed.
            $kpis = [];
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: self::ACTION,
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->reports->saveDraft(
                $unit->id,
                $weekStart,
                $kpis,
                $content->notes,
                $trainingSubject,
                $content->unaccounted,
            ),
        );
    }
}
