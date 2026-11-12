<?php

namespace App\Commands\ReportEdits;

use App\Authorization\ReportEditAuthority;
use App\Models\ReportEditRequest;
use App\Models\User;
use App\Notifications\ReportEditDecided;
use App\Repositories\AuditedBatch;
use App\Repositories\ReportEditRequestRepository;
use App\Repositories\ReportEditWindow;
use App\Repositories\StaffReportRepository;
use App\Repositories\UserRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekLabel;

/**
 * Approves or denies a pending request to edit a locked report. Only a leader above the report's unit, or an Admin,
 * decides ({@see ReportEditAuthority}), and never the person who asked. Approving unlocks the report until the coming
 * Saturday 2359Z ({@see ReportEditWindow::unlockUntil()}): the leader edits and sends it again as before the deadline
 * (a weekly report through Edit report, a staff report by sending it again). One audited transaction
 * (`report_edit.approved` or `report_edit.denied`, target the report, the decided request with it, the note as the
 * reason), then the bell tells whoever asked.
 *
 * Refused before anything is written: an unknown request, an actor who may not decide it (403), a request already
 * decided, and an overlong note. Two decisions at once: the second finds it decided under the row lock.
 */
class DecideReportEditHandler
{
    public const APPROVED = 'report_edit.approved';

    public const DENIED = 'report_edit.denied';

    public const MAX_NOTE = 500;

    public function __construct(
        private readonly ReportEditAuthority $authority,
        private readonly ReportEditRequestRepository $requests,
        private readonly WeeklyReportRepository $weeklyReports,
        private readonly StaffReportRepository $staffReports,
        private readonly UserRepository $users,
        private readonly ReportEditRecipients $recipients,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws ReportEditForbiddenException
     * @throws ReportEditRefusedException
     */
    public function handle(User $actor, DecideReportEdit $command): ReportEditRequest
    {
        $request = $this->requests->find($command->requestId) ?? throw ReportEditRefusedException::unknown();

        if (! $this->authority->mayDecide($actor, $request)) {
            throw ReportEditForbiddenException::decide();
        }

        if ($request->status !== ReportEditRequest::PENDING) {
            throw ReportEditRefusedException::decided();
        }

        $note = trim((string) $command->note);
        $note = $note === '' ? null : $note;

        if ($note !== null && mb_strlen($note) > self::MAX_NOTE) {
            throw ReportEditRefusedException::noteTooLong(self::MAX_NOTE);
        }

        $until = $command->approve ? ReportEditWindow::unlockUntil() : null;
        $weekStart = $request->week_start;
        $decided = null;

        $this->audit->recordBatch(
            actorId: $actor->id,
            action: $command->approve ? self::APPROVED : self::DENIED,
            reason: $note,
            reasonRequired: false,
            mutation: function () use ($request, $command, $actor, $note, $until, $weekStart, &$decided) {
                $decided = $this->requests->decide($request->id, $command->approve, $actor->id, $note, $until)
                    ?? throw ReportEditRefusedException::decided();

                $report = $request->isStaff()
                    ? ($until === null ? $this->staffReports->find($request->trooper_id, $weekStart) : $this->staffReports->unlock($request->trooper_id, $weekStart, $until))
                    : ($until === null ? $this->weeklyReports->find($request->unit_id, $weekStart) : $this->weeklyReports->unlock($request->unit_id, $weekStart, $until));

                return new AuditedBatch($report ?? $decided, $decided);
            },
        );

        $requester = $this->users->find($request->requested_by);

        if ($requester !== null && $requester->id !== $actor->id) {
            $requester->notify(new ReportEditDecided(
                $this->recipients->nameOf($actor),
                $this->subject($request),
                $command->approve,
                $until === null ? null : 'Sat '.WeekLabel::date($until).' '.WeekLabel::zulu($until),
                $note,
                $request->isStaff()
                    ? "/staff-report/{$request->trooper_id}/{$weekStart->toDateString()}"
                    : "/reports/{$request->unit_id}/{$weekStart->toDateString()}",
            ));
        }

        return $decided;
    }

    private function subject(ReportEditRequest $request): string
    {
        $week = WeekLabel::range($request->week_start);

        return $request->isStaff()
            ? "your staff report for {$week}"
            : ($request->unit->callsign ?? $request->unit->name)."'s weekly report for {$week}";
    }
}
