<?php

namespace App\Commands\WeeklyReports;

use App\Authorization\ScopeResolver;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use App\Repositories\ReportEditWindow;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportAlreadySentException;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Audit\ReasonRequiredException;
use App\Support\Time\WeekOf;

/**
 * Reopens a sent weekly report for editing ("Edit report"), until it locks at its deadline ({@see ReportEditWindow}).
 * Anyone who could send the report now may reopen it — the same write scope check as {@see SendWeeklyReportHandler},
 * not only whoever sent it — with a reason, as one audited transaction (`weekly_report.reopened`, AD-16) whose digest
 * is over the row as it was frozen, so the trail holds the version being replaced.
 *
 * A reopened report stays sent: its snapshot stays the record, and roll-ups keep reading it, until a send freezes a new
 * one (the earlier one is kept in `revisions`). While it is reopened the week's rollcall is open again
 * (`WeeklyReportRepository::isSentFor()` is false), so marks can be fixed before the re-send. Nobody sending it again
 * changes nothing: at the deadline it locks with the snapshot it had.
 *
 * Refused before anything is written: a unit outside the actor's write scope or off the line (403), a blank reason, a
 * week before the floor, a report that is not sent, and one past its deadline. Reopening one already reopened is
 * audited again and changes nothing else.
 */
class ReopenWeeklyReportHandler
{
    public const ACTION = 'weekly_report.reopened';

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly WeeklyReportRepository $reports,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws SendWeeklyReportForbiddenException when the unit is outside the actor's write scope, or does not exist.
     * @throws ReasonRequiredException when no reason is given.
     * @throws InvalidReportContentException when the week is before `WeekOf::floor()`.
     * @throws WeeklyReportLockedException when the report is not sent, or its deadline has passed.
     */
    public function handle(User $actor, ReopenWeeklyReport $command): WeeklyReport
    {
        $unit = $this->scope->writeScope($actor)->contains($command->unitId) ? $this->units->find($command->unitId) : null;

        if ($unit === null || ! $unit->isLine()) {
            throw new SendWeeklyReportForbiddenException($command->unitId);
        }

        if (blank($command->reason)) {
            throw ReasonRequiredException::forAction(self::ACTION);
        }

        $weekStart = WeekOf::start($command->week);

        if (WeekOf::isBeforeFloor($weekStart)) {
            throw InvalidReportContentException::weekBeforeFloor($weekStart);
        }

        $report = $this->reports->find($unit->id, $weekStart);

        if ($report?->status !== WeeklyReportStatus::Sent) {
            throw WeeklyReportLockedException::notSent();
        }

        // Past its deadline only while an approved request to edit it holds ({@see ReportEditWindow::mayEdit()}).
        if (! ReportEditWindow::mayEdit($weekStart, $report->unlocked_until)) {
            throw WeeklyReportLockedException::deadlinePassed(ReportEditWindow::editableUntil($weekStart, $report->unlocked_until));
        }

        try {
            return $this->audit->record(
                actorId: $actor->id,
                action: self::ACTION,
                reason: $command->reason,
                reasonRequired: true,
                mutation: fn () => $this->reports->reopen($unit->id, $weekStart, $actor->id),
            );
        } catch (WeeklyReportAlreadySentException) {
            // The deadline passed between the check above and the row lock.
            throw WeeklyReportLockedException::deadlinePassed(ReportEditWindow::editableUntil($weekStart, $report->unlocked_until));
        }
    }
}
