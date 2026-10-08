<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Commands\Attendance\AttendanceMark;
use App\Commands\Attendance\InvalidAttendanceMarkException;
use App\Commands\Attendance\MarkAttendance;
use App\Commands\Attendance\MarkAttendanceForbiddenException;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Kpi\KpiService;
use App\Kpi\TrooperAttendance;
use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The weekly rollcall of one section (Stories 3.7–3.10, binding design: mockups/v2/rollcall-r3.html
 * "Guided week"): who signed the rollcall thread, who attended Section Practice, and the forum post.
 *
 * Every read is gated by the ScopeResolver (AD-8) — a section outside the viewer's scope is a 403,
 * whatever the UI offers. A week is any moment in it (`?week=YYYY-MM-DD`), bucketed by `WeekOf`
 * (AD-11); a future week is never served — there is nothing to mark yet.
 */
class RollcallController extends Controller
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly UnitCommandMappingRepository $commands,
        private readonly TrooperRepository $troopers,
        private readonly SectionPracticeRepository $practices,
        private readonly AttendanceRepository $attendance,
        private readonly SyncRunRepository $syncRuns,
        private readonly KpiService $kpis,
    ) {}

    /**
     * The rollcall of `$unit` for the requested week. Without a unit, a section leader lands on
     * their own section and anyone whose scope holds exactly one section on that one; everyone
     * else gets the section picker.
     */
    public function show(Request $request, ?int $unit = null): Response|RedirectResponse
    {
        $request->validate(['week' => ['nullable', 'date_format:Y-m-d']]);

        $user = $request->user();
        $currentWeek = WeekOf::start(CarbonImmutable::now());
        $weekStart = $this->requestedWeek($request->query('week'), $currentWeek);

        if ($weekStart->greaterThan($currentWeek)) {
            return redirect()->route('rollcall.show', array_filter(['unit' => $unit]));
        }

        $scope = $this->scope->resolve($user);
        $sections = $this->units->sectionsAmong($scope);
        $weekQuery = $weekStart->equalTo($currentWeek) ? [] : ['week' => $weekStart->toDateString()];

        if ($unit === null) {
            $landing = $this->landingSection($user, $sections);

            if ($landing !== null) {
                return redirect()->route('rollcall.show', ['unit' => $landing->id, ...$weekQuery]);
            }

            return Inertia::render('Rollcall', [
                'section' => null,
                'sections' => $this->pickerOptions($sections),
                'week' => $this->weekProps($weekStart, $currentWeek),
            ]);
        }

        $section = $this->units->find($unit);
        abort_if($section === null, 404);
        abort_unless($scope->contains($section->id), 403, 'This section is outside your scope.');
        abort_unless($section->type === UnitType::Section, 404);

        return Inertia::render('Rollcall', [
            'section' => $this->sectionProps($section, $sections),
            'sections' => $this->pickerOptions($sections),
            'week' => $this->weekProps($weekStart, $currentWeek),
            ...$this->rosterProps($section, $weekStart),
        ]);
    }

    /**
     * Autosave from the rollcall screen (3.8): a batch of per-trooper changes — a practice mark, a
     * rollcall signature, or both — written through the audited `MarkAttendance` Command, the one
     * writer of record (AD-16, AD-22). "Mark all present" is the same batch. Answers JSON, since
     * the page saves in the background and must keep the leader's input on a failure: 422 for a
     * batch nobody may record, 403 outside scope (AD-8). On success it returns the at-risk flags
     * recomputed by the KPI service (AD-21), so the review step reflects the saved marks.
     */
    public function store(Request $request, int $unit, MarkAttendanceHandler $handler): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'week' => ['required', 'date_format:Y-m-d'],
            'marks' => ['present', 'array', 'max:500'],
            'marks.*.trooper_id' => ['required', 'integer', 'distinct'],
            'marks.*.status' => ['nullable', Rule::in(array_map(fn (AttendanceStatus $s) => $s->value, [
                AttendanceStatus::Present, AttendanceStatus::Missed, AttendanceStatus::Makeup, AttendanceStatus::Attached,
            ]))],
            'marks.*.rollcall' => ['nullable', Rule::enum(RollcallSignature::class)],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $weekStart = $this->requestedWeek($request->input('week'), WeekOf::start(CarbonImmutable::now()));

        if ($weekStart->greaterThan(WeekOf::start(CarbonImmutable::now()))) {
            return response()->json(['message' => 'A future week cannot be marked yet.'], 422);
        }

        $section = $this->units->find($unit);

        if ($section === null) {
            return response()->json(['message' => 'No such section.'], 404);
        }

        try {
            $marks = array_map(fn (array $mark) => new AttendanceMark(
                trooperId: (int) $mark['trooper_id'],
                status: isset($mark['status']) ? AttendanceStatus::from($mark['status']) : null,
                rollcall: isset($mark['rollcall']) ? RollcallSignature::from($mark['rollcall']) : null,
            ), $request->input('marks', []));

            $handler->handle($request->user(), new MarkAttendance($section->id, $weekStart, ...$marks));
        } catch (MarkAttendanceForbiddenException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (InvalidAttendanceMarkException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'savedAt' => CarbonImmutable::now()->toIso8601String(),
            'atRisk' => $this->atRisk($section, $weekStart),
        ]);
    }

    private function requestedWeek(?string $week, CarbonImmutable $currentWeek): CarbonImmutable
    {
        return $week === null ? $currentWeek : WeekOf::start(CarbonImmutable::createFromFormat('!Y-m-d', $week, 'UTC'));
    }

    /**
     * The section a user opens the rollcall on without choosing: the one their billet commands, or
     * the only section in their scope. Null leaves the choice to the picker.
     *
     * @param  Collection<int, Unit>  $sections  the sections in the user's scope
     */
    private function landingSection(User $user, Collection $sections): ?Unit
    {
        $commanded = $this->commands->commandedUnitFor($user);

        if ($commanded !== null && $sections->contains('id', $commanded->id)) {
            return $commanded;
        }

        return $sections->count() === 1 ? $sections->first() : null;
    }

    /**
     * @param  Collection<int, Unit>  $sections
     * @return list<array<string, mixed>>
     */
    private function pickerOptions(Collection $sections): array
    {
        return $sections->map(fn (Unit $section) => [
            'id' => $section->id,
            'designation' => $section->callsign ?? $section->name,
            'name' => $section->name,
            'platoon' => $section->parent ? ($section->parent->callsign ?? $section->parent->name) : null,
        ])->values()->all();
    }

    /**
     * @param  Collection<int, Unit>  $sections
     * @return array<string, mixed>
     */
    private function sectionProps(Unit $section, Collection $sections): array
    {
        $parent = $sections->firstWhere('id', $section->id)?->parent;

        return [
            'id' => $section->id,
            'designation' => $section->callsign ?? $section->name,
            'name' => $section->name,
            'platoon' => $parent ? ($parent->callsign ?? $parent->name) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function weekProps(CarbonImmutable $weekStart, CarbonImmutable $currentWeek): array
    {
        return [
            // Saturday 00:00 UTC (AD-11); the rollcall thread's signatures close the Friday after.
            'start' => $weekStart->toIso8601String(),
            'date' => $weekStart->toDateString(),
            'closes' => $weekStart->addWeek()->subMinute()->toIso8601String(),
            'isCurrent' => $weekStart->equalTo($currentWeek),
            'previous' => $weekStart->subWeek()->toDateString(),
            'next' => $weekStart->lessThan($currentWeek) ? $weekStart->addWeek()->toDateString() : null,
            'current' => $currentWeek->toDateString(),
        ];
    }

    /**
     * The section's troopers with this week's marks, last week's practice marks (the pre-fill
     * suggestion, 3.7), the at-risk flags from the shared KPI service (AD-21), and when the roster
     * they come from was last synced (AD-20).
     *
     * @return array<string, mixed>
     */
    private function rosterProps(Unit $section, CarbonImmutable $weekStart): array
    {
        $practice = $this->practices->find($section->id, $weekStart);
        $marks = $practice ? $this->attendance->forPractice($practice)->keyBy('trooper_id') : collect();

        $priorPractice = $this->practices->find($section->id, $weekStart->subWeek());
        $prior = $priorPractice
            ? $this->attendance->forPractice($priorPractice)->filter(fn (Attendance $row) => $row->status !== null)->keyBy('trooper_id')
            : collect();

        $roster = $this->troopers->placedIn($section->id);
        $lastSync = $this->syncRuns->latestSuccessful('roster');

        return [
            'troopers' => $roster->map(fn (Trooper $trooper) => [
                'id' => $trooper->id,
                'cavId' => $trooper->cav_id,
                'rank' => $trooper->rank,
                'name' => $trooper->user?->username,
                'rollcall' => $marks->get($trooper->id)?->rollcall->value ?? 'unsigned',
                'status' => $marks->get($trooper->id)?->status?->value,
                'source' => $marks->get($trooper->id)?->source?->value,
                'priorStatus' => $prior->get($trooper->id)?->status->value,
            ])->sortBy(fn (array $row) => mb_strtolower($row['name'] ?? "~{$row['cavId']}"))->values()->all(),
            'priorWeekMarked' => $prior->isNotEmpty(),
            'atRisk' => $this->atRisk($section, $weekStart),
            'roster' => [
                'source' => 'milpac',
                'syncedAt' => $lastSync?->finished_at?->toIso8601String(),
            ],
            'practice' => [
                'lastMarkedAt' => $marks->max(fn (Attendance $row) => $row->updated_at)?->toIso8601String(),
            ],
        ];
    }

    /**
     * The section's at-risk troopers this week (AD-21's `TrooperAttendance::atRisk()`), with their
     * misses in the trailing window — computed by the KPI service, never here.
     *
     * @return list<array{trooperId: int, missed: int, windowWeeks: int}>
     */
    private function atRisk(Unit $section, CarbonImmutable $weekStart): array
    {
        return $this->kpis->trooperAttendance($section, $weekStart)
            ->filter(fn (TrooperAttendance $row) => $row->atRisk())
            ->map(fn (TrooperAttendance $row) => [
                'trooperId' => $row->trooperId,
                'missed' => $row->missed,
                'windowWeeks' => KpiService::WINDOW_WEEKS,
            ])->values()->all();
    }
}
