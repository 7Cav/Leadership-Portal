<?php

namespace App\Http\Controllers;

use App\Commands\ReportEdits\DecideReportEdit;
use App\Commands\ReportEdits\DecideReportEditHandler;
use App\Commands\ReportEdits\ReportEditRefusedException;
use App\Commands\ReportEdits\RequestReportEdit;
use App\Commands\ReportEdits\RequestReportEditHandler;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Requests to edit a report that locked at its deadline, and the decisions on them: "Request edit" on a sent weekly
 * or staff report, "Approve" and "Deny" for a leader above. Each answers back on the page it came from. The Commands
 * decide who may; a refusal comes back as a form error.
 */
class ReportEditRequestController extends Controller
{
    public function requestWeekly(Request $request, RequestReportEditHandler $handler, int $unit, string $week): RedirectResponse
    {
        $this->ask($handler, RequestReportEdit::weekly($unit, $this->week($week), $this->reason($request)));

        return back();
    }

    public function requestStaff(Request $request, RequestReportEditHandler $handler, int $trooper, string $week): RedirectResponse
    {
        $this->ask($handler, RequestReportEdit::staff($trooper, $this->week($week), $this->reason($request)));

        return back();
    }

    public function approve(Request $request, DecideReportEditHandler $handler, int $editRequest): RedirectResponse
    {
        return $this->decide($request, $handler, $editRequest, true);
    }

    public function deny(Request $request, DecideReportEditHandler $handler, int $editRequest): RedirectResponse
    {
        return $this->decide($request, $handler, $editRequest, false);
    }

    private function ask(RequestReportEditHandler $handler, RequestReportEdit $command): void
    {
        try {
            $handler->handle(request()->user(), $command);
        } catch (ReportEditRefusedException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }
    }

    private function decide(Request $request, DecideReportEditHandler $handler, int $editRequest, bool $approve): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:'.DecideReportEditHandler::MAX_NOTE]], [
            'note.max' => 'Keep the note to :max characters.',
        ]);

        try {
            $handler->handle($request->user(), new DecideReportEdit($editRequest, $approve, $data['note'] ?? null));
        } catch (ReportEditRefusedException $e) {
            throw ValidationException::withMessages([$e->field === 'report' ? 'decision' : $e->field => $e->getMessage()]);
        }

        return back();
    }

    private function reason(Request $request): ?string
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:'.RequestReportEditHandler::MAX_REASON]], [
            'reason.max' => 'Keep the reason to :max characters.',
        ]);

        return $data['reason'] ?? null;
    }

    /** A Saturday that opens a Cav week no later than the current one, else 404. */
    private function week(string $week): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $week, 'UTC');

        if ($date === false || $date->toDateString() !== $week || ! WeekOf::start($date)->equalTo($date) || $date->greaterThan(WeekOf::start(CarbonImmutable::now('UTC')))) {
            abort(404);
        }

        return $date;
    }
}
