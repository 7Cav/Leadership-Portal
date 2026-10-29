<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Authorization\StaffReportAccess;
use App\Commands\StaffReports\SendStaffReport;
use App\Commands\StaffReports\SendStaffReportHandler;
use App\Commands\StaffReports\StaffReportForbiddenException;
use App\Commands\StaffReports\StaffReportRefusedException;
use App\Kpi\Headcount;
use App\Kpi\KpiService;
use App\Milpac\StaffReportWeek;
use App\Models\Unit;
use App\Models\UnitType;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The weekly staff report: a platoon or company HQ staff member says whether they attended a
 * section practice that week, and which. One page per staff member and week. `/staff-report` opens the viewer's own for
 * the week whose report cycle is running. Orchestration only: the send is one audited Command whose handler decides who
 * may ({@see StaffReportAccess}: the staff member alone) and which week; a view-as preview is refused before it gets
 * here (`ReadOnlyWhileViewingAs`).
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
    ) {}

    /** The viewer's own staff report for the week whose report cycle is running. */
    public function own(Request $request): RedirectResponse
    {
        $trooper = $this->access->ownTrooper($request->user()) ?? abort(404);

        return redirect()->route('staff-report.show', [
            'trooper' => $trooper->id,
            'week' => WeekOf::reporting(CarbonImmutable::now())->toDateString(),
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
        $canSend = $self && $report === null && $hq !== null && $trooper->status->isServing() && ! $beforeFloor
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
            ],
            'report' => $report === null ? null : $this->withOwn($report, $hq),
            'canSend' => $canSend,
            // What the picker starts from: the week's staff practice entries (ticked, with who entered each) and the
            // practices the game server saw them at (a hint, never ticked for them).
            'entries' => $report === null ? $this->week->entries($trooper, $weekStart) : [],
            'seen' => $canSend ? $this->week->seenSectionIds($trooper, $weekStart) : [],
            'sections' => $canSend ? $this->sections($hq) : [],
            'maxPractices' => SendStaffReportHandler::MAX_PRACTICES,
        ]);
    }

    public function send(Request $request, SendStaffReportHandler $handler, int $trooper, string $week): RedirectResponse
    {
        $found = $this->troopers->find($trooper);
        abort_unless($found !== null && ($this->scope->isOwnTrooper($request->user(), $found) || $this->scope->canSee($request->user(), $found)), 404);
        $weekStart = $this->weekStart($week);

        $data = $request->validate([
            'attended' => ['required', 'boolean'],
            'section_ids' => ['present', 'array', 'max:'.SendStaffReportHandler::MAX_PRACTICES],
            'section_ids.*' => ['integer'],
        ], [
            'attended.required' => 'Answer whether you attended a section practice this week.',
            'section_ids.max' => 'Pick at most :max practices.',
        ]);

        try {
            $handler->handle($request->user(), new SendStaffReport(
                $found->id,
                $weekStart,
                (bool) $data['attended'],
                array_map('intval', $data['section_ids']),
            ));
        } catch (StaffReportForbiddenException $e) {
            abort(403, $e->getMessage());
        } catch (StaffReportRefusedException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
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
