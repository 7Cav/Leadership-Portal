<?php

namespace App\Commands\WeeklyReports;

use App\Authorization\ScopeResolver;
use App\Kpi\ReportKpisUnavailableException;
use App\Kpi\WeeklyReportKpis;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportAlreadySentException;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekOf;

/**
 * Saves a unit-week's draft report (5.5) through the audited envelope (AD-16; no reason — a draft
 * changes nothing anyone else reads as reported). The row's `kpis` is refreshed to the live
 * snapshot as a last saved copy only; a draft is always re-computed for display and the send
 * freezes its own (AD-12).
 *
 * Refused before anything is written: a unit outside the actor's scope (AD-8), content that is
 * not the section's to record, and a report that is already sent — a sent report is frozen.
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
    ) {}

    /**
     * @throws SendWeeklyReportForbiddenException when the unit is outside the actor's scope, or
     *                                            does not exist.
     * @throws InvalidReportContentException when the content is not the unit's to record.
     * @throws ReportKpisUnavailableException when the unit's KPIs cannot be computed.
     * @throws WeeklyReportAlreadySentException when the unit-week is already sent.
     */
    public function handle(User $actor, SaveReportDraft $command): WeeklyReport
    {
        $unit = $this->scope->resolve($actor)->contains($command->unitId) ? $this->units->find($command->unitId) : null;

        if ($unit === null) {
            throw new SendWeeklyReportForbiddenException($command->unitId);
        }

        $content = ReportContent::checked($unit, $command->notes, $command->trainingSubject, $command->unaccounted, $this->troopers);
        $weekStart = WeekOf::start($command->week);
        $kpis = $this->snapshots->snapshot($unit, $weekStart)->toArray();

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
                $content->trainingSubject,
                $content->unaccounted,
            ),
        );
    }
}
