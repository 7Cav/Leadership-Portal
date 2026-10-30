<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Authorization\StaffReportAccess;
use App\Commands\StaffReports\PushStaffReportToSheet;
use App\Commands\StaffReports\PushStaffReportToSheetHandler;
use App\Commands\StaffReports\SendStaffReport;
use App\Commands\StaffReports\SendStaffReportHandler;
use App\Commands\StaffReports\StaffReportForbiddenException;
use App\Commands\StaffReports\StaffReportRefusedException;
use App\Jobs\PushStaffReportToSheetJob;
use App\Kpi\Headcount;
use App\Kpi\KpiService;
use App\Milpac\StaffReportWeek;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The weekly staff report: a platoon, company or battalion HQ staff member says whether they attended a
 * section practice or an FTX that week, and which, with the other events they attended and their notes. One page per
 * staff member and week. `/staff-report` opens the viewer's own for the last week to have ended, the one whose report
 * is due: a week is sent only once it is over. The running week reads as not sendable yet. Orchestration only: the send
 * is one audited Command whose handler decides who may ({@see StaffReportAccess}: the staff member alone) and which
 * week; a view-as preview is refused before it gets here (`ReadOnlyWhileViewingAs`).
 *
 * Reading one: the staff member, the chain above their HQ (as the unit above it is read) and Admins. Anyone else, and a
 * trooper who files no report for that week, answers 404, like a page that does not exist (AD-8).
 */
class StaffReportController extends Controller
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly StaffReportAccess $access,
        private readonly TrooperRepository $troopers,
        private readonly UnitRepository $units,
        private readonly StaffReportWeek $week,
        private readonly Headcount $headcount,
        private readonly PushStaffReportToSheetHandler $sheet,
    ) {}

    /** The viewer's own staff report for the last week to have ended: the one due. */
    public function own(Request $request): RedirectResponse
    {
        $trooper = $this->access->ownTrooper($request->user()) ?? abort(404);

        return redirect()->route('staff-report.show', [
            'trooper' => $trooper->id,
            'week' => WeekOf::lastEnded(CarbonImmutable::now())->toDateString(),
        ]);
    }

    public function show(Request $request, int $trooper, string $week): Response
    {
        $trooper = $this->troopers->find($trooper) ?? abort(404);
        $weekStart = $this->weekStart($week);
        $user = $request->user();
        $self = $this->scope->isOwnTrooper($user, $trooper);
        $hq = $this->access->hqInWeek($trooper, $weekStart);

        // The staff member always reaches their own page, to read that a week has no report for them; anyone else only
        // a report that exists for them to read.
        abort_unless($self || ($hq !== null && $this->access->mayRead($user, $trooper, $hq)), 404);

        $report = $this->week->report($trooper, $weekStart);
        $beforeFloor = WeekOf::isBeforeFloor($weekStart);
        $ended = WeekOf::hasEnded($weekStart, CarbonImmutable::now());
        $canSend = $self && $report === null && $hq !== null && $trooper->status->isServing() && ! $beforeFloor && $ended
            && $this->access->maySend($user, $trooper);
        $current = WeekOf::start(CarbonImmutable::now());

        return Inertia::render('StaffReport', [
            'trooper' => ['id' => $trooper->id, 'displayName' => $trooper->display_name],
            'self' => $self,
            'hq' => $hq === null ? null : ['id' => $hq->id, 'label' => $hq->callsign ?? $hq->name, 'type' => $hq->type->value],
            'week' => [
                'date' => $weekStart->toDateString(),
                'start' => $weekStart->toIso8601String(),
                'due' => WeekOf::reportDue($weekStart)->toIso8601String(),
                // As on a unit's report: an unsent one stops being called overdue once the week leaves the KPI window.
                'overdueUntil' => WeekOf::next($weekStart, KpiService::WINDOW_WEEKS)->toIso8601String(),
                'previous' => $weekStart->subWeek()->toDateString(),
                'next' => $weekStart->addWeek()->lessThanOrEqualTo($current) ? $weekStart->addWeek()->toDateString() : null,
                'beforeFloor' => $beforeFloor,
                // A running week is sent from the Saturday it ends.
                'ended' => $ended,
                'opensAt' => WeekOf::next($weekStart)->toIso8601String(),
            ],
            'report' => $report === null ? null : $this->withOwn($report, $hq),
            // Whether the reports Google Sheet is set up, so a reader is told when a row has not gone to it yet.
            'sheetConfigured' => $this->sheet->sheetConfigured(),
            'canSend' => $canSend,
            // What the picker starts from: the week's staff practice entries (ticked, with who entered each) and the
            // practices the game server saw them at (a hint, never ticked for them).
            'entries' => $report === null ? $this->week->entries($trooper, $weekStart) : [],
            'seen' => $canSend ? $this->week->seenSectionIds($trooper, $weekStart) : [],
            'sections' => $canSend ? $this->sections($hq) : [],
            'maxPractices' => SendStaffReportHandler::MAX_PRACTICES,
            'limits' => [
                'ftxName' => SendStaffReportHandler::MAX_FTX_NAME,
                'otherEvents' => SendStaffReportHandler::MAX_OTHER_EVENTS,
                'notes' => SendStaffReportHandler::MAX_NOTES,
            ],
        ]);
    }

    public function send(Request $request, SendStaffReportHandler $handler, Dispatcher $bus, int $trooper, string $week): RedirectResponse
    {
        $found = $this->troopers->find($trooper);
        abort_unless($found !== null && ($this->scope->isOwnTrooper($request->user(), $found) || $this->scope->canSee($request->user(), $found)), 404);
        $weekStart = $this->weekStart($week);

        $data = $request->validate([
            'attended' => ['required', 'boolean'],
            'section_ids' => ['present', 'array', 'max:'.SendStaffReportHandler::MAX_PRACTICES],
            'section_ids.*' => ['integer'],
            // The rules on what goes with what (an FTX's name and date, the week) are the handler's; these bound the request.
            'practice_type' => ['nullable', 'string', 'in:sp,ftx'],
            'ftx_name' => ['nullable', 'string', 'max:'.SendStaffReportHandler::MAX_FTX_NAME],
            'ftx_date' => ['nullable', 'string', 'max:10'],
            'other_events' => ['nullable', 'string', 'max:'.SendStaffReportHandler::MAX_OTHER_EVENTS],
            'notes' => ['nullable', 'string', 'max:'.SendStaffReportHandler::MAX_NOTES],
        ], [
            'attended.required' => 'Answer whether you attended a section practice this week.',
            'section_ids.max' => 'Pick at most :max practices.',
            'practice_type.in' => 'Pick Section practice (SP) or FTX.',
            'ftx_name.max' => 'Keep the FTX name to :max characters.',
            'other_events.max' => 'Keep the other events to :max characters.',
            'notes.max' => 'Keep the notes to :max characters.',
        ]);

        try {
            $report = $handler->handle($request->user(), new SendStaffReport(
                $found->id,
                $weekStart,
                (bool) $data['attended'],
                array_map('intval', $data['section_ids']),
                $data['practice_type'] ?? null,
                $data['ftx_name'] ?? null,
                $data['ftx_date'] ?? null,
                $data['other_events'] ?? null,
                $data['notes'] ?? null,
            ));
        } catch (StaffReportForbiddenException $e) {
            abort(403, $e->getMessage());
        } catch (StaffReportRefusedException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        // The copy to the reports Google Sheet is queued and never part of the send: if it cannot even be queued, the
        // report stands and reads "Not sent to the sheet yet".
        if ($this->sheet->sheetConfigured()) {
            rescue(fn () => $bus->dispatch(new PushStaffReportToSheetJob(new PushStaffReportToSheet($report->id))));
        }

        return redirect()->route('staff-report.show', ['trooper' => $found->id, 'week' => $weekStart->toDateString()]);
    }

    /**
     * Every section with troopers in it (#442), the staff member's own unit's first: the ones under their HQ are the
     * preferred practices, and the host's rollcall lists them there as a staff guest.
     *
     * @return list<array{id: int, label: string, own: bool}>
     */
    private function sections(?Unit $hq): array
    {
        return $this->headcount->withoutEmptyLineUnits($this->units->tree()->filter(fn (Unit $unit) => $unit->type === UnitType::Section))
            ->map(fn (Unit $unit) => [
                'id' => $unit->id,
                'label' => $unit->callsign ?? $unit->name,
                'own' => $hq !== null && str_starts_with($unit->path, $hq->path),
            ])
            ->sortBy([['own', 'desc'], ['label', 'asc']])
            ->values()
            ->all();
    }

    /**
     * @param  array{sentAt: string, attended: bool, practices: list<array{sectionId: int, section: ?string}>}  $report
     * @return array<string, mixed>
     */
    private function withOwn(array $report, ?Unit $hq): array
    {
        $paths = $this->units->tree()->keyBy('id');

        return [
            ...$report,
            'practices' => array_map(fn (array $practice) => [
                ...$practice,
                'own' => $hq !== null && ($section = $paths->get($practice['sectionId'])) instanceof Unit && str_starts_with($section->path, $hq->path),
            ], $report['practices']),
        ];
    }

    /** `YYYY-MM-DD` of a Saturday that opens a Cav week no later than the current one (AD-11), else 404. */
    private function weekStart(string $week): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $week, 'UTC');

        abort_if($date === false || $date->toDateString() !== $week || ! WeekOf::start($date)->equalTo($date)
            || $date->greaterThan(WeekOf::start(CarbonImmutable::now())), 404);

        return $date;
    }
}
