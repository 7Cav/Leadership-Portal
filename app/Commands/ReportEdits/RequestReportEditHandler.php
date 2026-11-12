<?php

namespace App\Commands\ReportEdits;

use App\Authorization\RealActor;
use App\Authorization\ScopeResolver;
use App\Authorization\StaffReportAccess;
use App\Commands\WeeklyReports\ReopenWeeklyReportHandler;
use App\Models\ReportEditRequest;
use App\Models\User;
use App\Models\WeeklyReportStatus;
use App\Notifications\ReportEditRequested;
use App\Repositories\AuditedBatch;
use App\Repositories\ReportEditRequestRepository;
use App\Repositories\ReportEditWindow;
use App\Repositories\StaffReportRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekLabel;
use App\Support\Time\WeekOf;
use Illuminate\Support\Facades\Notification;

/**
 * Asks to edit a report that locked at its deadline. Whoever could send the report asks: for a unit's weekly report,
 * anyone whose write scope holds the unit (as {@see ReopenWeeklyReportHandler} checks);
 * for a staff report, only the staff member. A reason is required. One audited transaction
 * (`report_edit.requested`, target the report, the new request with it), then the bell tells the leaders one level
 * above ({@see ReportEditRecipients}). A leader above decides it ({@see DecideReportEditHandler}).
 *
 * Refused before anything is written: a unit outside scope or off the line, or someone else's staff report (403); a
 * week before the floor; a report not sent; one still before its deadline or an approved unlock (Edit report does
 * that); one with a request already waiting; and a blank or overlong reason.
 */
class RequestReportEditHandler
{
    public const ACTION = 'report_edit.requested';

    public const MAX_REASON = 1000;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly StaffReportAccess $staffAccess,
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly WeeklyReportRepository $weeklyReports,
        private readonly StaffReportRepository $staffReports,
        private readonly ReportEditRequestRepository $requests,
        private readonly ReportEditRecipients $recipients,
        private readonly AuditEnvelope $audit,
        private readonly RealActor $real,
    ) {}

    /**
     * @throws ReportEditForbiddenException
     * @throws ReportEditRefusedException
     */
    public function handle(User $actor, RequestReportEdit $command): ReportEditRequest
    {
        $weekStart = WeekOf::start($command->week);
        $staff = $command->trooperId !== null;

        if ($staff) {
            $trooper = $this->troopers->find($command->trooperId);

            if ($trooper === null || ! $this->staffAccess->maySend($actor, $trooper)) {
                throw ReportEditForbiddenException::request();
            }

            $report = $this->staffReports->find($trooper->id, $weekStart);
            $unit = $report?->unit_id === null ? $this->staffAccess->hqInWeek($trooper, $weekStart) : $this->units->find($report->unit_id);
            $subject = "{$trooper->display_name}'s staff report for ".WeekLabel::range($weekStart);
            $url = "/staff-report/{$trooper->id}/{$weekStart->toDateString()}";
        } else {
            $unit = $this->scope->writeScope($actor)->contains($command->unitId) ? $this->units->find($command->unitId) : null;

            if ($unit === null || ! $unit->isLine()) {
                throw ReportEditForbiddenException::request();
            }

            $report = $this->weeklyReports->find($unit->id, $weekStart);
            $report = $report?->status === WeeklyReportStatus::Sent ? $report : null;
            $subject = ($unit->callsign ?? $unit->name)."'s weekly report for ".WeekLabel::range($weekStart);
            $url = "/reports/{$unit->id}/{$weekStart->toDateString()}";
        }

        if (WeekOf::isBeforeFloor($weekStart)) {
            throw ReportEditRefusedException::weekBeforeFloor();
        }

        if ($report === null || $unit === null) {
            throw ReportEditRefusedException::notSent();
        }

        if (! ReportEditWindow::isLocked($weekStart, $report->unlocked_until)) {
            throw ReportEditRefusedException::stillOpen(ReportEditWindow::editableUntil($weekStart, $report->unlocked_until));
        }

        $reason = trim((string) $command->reason);

        if ($reason === '') {
            throw ReportEditRefusedException::reasonRequired();
        }

        if (mb_strlen($reason) > self::MAX_REASON) {
            throw ReportEditRefusedException::reasonTooLong(self::MAX_REASON);
        }

        if ($this->requests->hasPending($unit->id, $staff ? $command->trooperId : null, $weekStart)) {
            throw ReportEditRefusedException::pending();
        }

        $request = null;
        $this->audit->recordBatch(
            actorId: $actor->id,
            action: self::ACTION,
            reason: $reason,
            reasonRequired: true,
            mutation: function () use ($unit, $staff, $command, $weekStart, $actor, $reason, $report, &$request) {
                $request = $this->requests->create($unit->id, $staff ? $command->trooperId : null, $weekStart, $actor->id, $this->real->get()?->id, $reason)
                    ?? throw ReportEditRefusedException::pending();

                return new AuditedBatch($report, $request);
            },
        );

        $recipients = $this->recipients->above($unit, [$actor->id]);

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new ReportEditRequested($this->recipients->nameOf($actor), $subject, $reason, $url));
        }

        return $request;
    }
}
