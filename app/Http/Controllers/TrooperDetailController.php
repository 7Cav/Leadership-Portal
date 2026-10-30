<?php

namespace App\Http\Controllers;

use App\Authorization\CounselingAccess;
use App\Authorization\CounselingPermissions;
use App\Authorization\ScopeResolver;
use App\Authorization\StaffPracticeAccess;
use App\Commands\Counseling\CounselingFormParties;
use App\Commands\StaffPractices\RecordStaffPracticeHandler;
use App\Kpi\AttendanceHistory;
use App\Kpi\Headcount;
use App\Kpi\KpiService;
use App\Milpac\CounselingHistory;
use App\Milpac\StaffPracticeHistory;
use App\Milpac\TrooperMilpac;
use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One trooper's milpac (Stories 6.3 to 6.7): the page every entry point to a trooper opens. It orchestrates
 * only; what the page shows comes from {@see TrooperMilpac}, and the practices the trooper missed in the KPI
 * window come from the shared {@see KpiService} (AD-21), the same rows the roster and Home read.
 *
 * Who may see it is decided by the single ScopeResolver (AD-8, AD-6), the same `canSee` gate the roster Sheet
 * uses: a trooper outside the viewer's effective scope answers 404 exactly like an id that does not exist,
 * whatever link led there, so ids cannot be enumerated. A view-as preview narrows it as it would for that
 * leader (AD-9). A trooper the sync has not placed anywhere belongs to no scope, so only an Admin sees them.
 *
 * Every signed-in trooper may also open their own page (decided 2026-10-28, {@see ScopeResolver::isOwnTrooper()}),
 * read-only for what their scope does not reach: the quick actions and the makeup PM follow the scope as before, so they
 * stay hidden, and the counseling and staff practice panels decide what the trooper may do on their own record.
 */
class TrooperDetailController extends Controller
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly TrooperMilpac $milpac,
        private readonly UnitRepository $units,
        private readonly KpiService $kpis,
        private readonly AttendanceHistory $history,
        private readonly CounselingAccess $access,
        private readonly CounselingHistory $counselingHistory,
        private readonly StaffPracticeAccess $staffAccess,
        private readonly StaffPracticeHistory $staffHistory,
        private readonly Headcount $headcount,
        private readonly CounselingFormParties $formParties,
    ) {}

    /**
     * How many Cav weeks back, the current one included, the staff practice form offers (never before the floor): the
     * window the entry's handler enforces, one number for both (audit 2, L3).
     */
    public const STAFF_PRACTICE_WEEKS = RecordStaffPracticeHandler::WEEKS;

    public function show(Request $request, Trooper $trooper): Response
    {
        $user = $request->user();

        $inScope = $this->scope->canSee($user, $trooper);
        abort_unless($inScope || $this->scope->isOwnTrooper($user, $trooper), 404);

        return Inertia::render('TrooperDetail', [
            ...$this->milpac->for($trooper, $this->scope->resolve($user), $this->missedWeeks($trooper)),
            // Opened only because it is the viewer's own page (2026-10-28): the page leaves out what leads into their unit.
            'ownPage' => ! $inScope,
            // Their attendance across every unit they were in (2026-10-27): anyone who may see the trooper now sees
            // the weeks from before a transfer too. Past weekly reports stay with the unit that sent them.
            'attendance' => $this->history->forTrooper($trooper, WeekOf::start(CarbonImmutable::now())),
            'counseling' => $this->counseling($user, $trooper),
            'staffPractices' => $this->staffPractices($user, $trooper),
        ]);
    }

    /**
     * The DCSs and LORs this viewer may see (#381), with what they may do: file either type, and change each record.
     * During a view-as preview nothing is offered, since every write is refused then (AD-9), and nothing on a trooper
     * who has left the Cav, whose records are read-only.
     *
     * @return array<string, mixed>
     */
    private function counseling(User $user, Trooper $trooper): array
    {
        $permissions = $this->access->for($user, $trooper);
        $writable = $this->scope->activeViewAs($user) === null;
        $files = array_values(array_filter(CounselingType::cases(), fn (CounselingType $type) => $writable && $permissions->files($type)));

        return [
            ...$this->counselingHistory->for(
                $trooper,
                $permissions->seesDcs,
                $permissions->seesLor,
                fn (CounselingRecord $record) => $writable && $permissions->changes($record),
                $files,
                // A frozen LOR (72 hours after filing) is overturned by the Regimental Executive Officer only.
                fn (CounselingRecord $record) => $writable && $permissions->overturns($record),
                fn (CounselingRecord $record) => CounselingPermissions::frozen($record),
                $permissions->seesExpired,
                // Within those 72 hours the trooper, signed in as themselves, may appeal (or comment on a DCS).
                fn (CounselingRecord $record) => $writable && $permissions->appeals($record),
            ),
            // Left the Cav: the records are read-only for everyone, and the panel says so.
            'departed' => $permissions->departed,
            // Which types the list can hold for this viewer, so an empty list says what it is empty of.
            'sees' => [
                CounselingType::Dcs->value => $permissions->seesDcs,
                CounselingType::Lor->value => $permissions->seesLor,
            ],
            'canFile' => [
                CounselingType::Dcs->value => $writable && $permissions->filesDcs,
                CounselingType::Lor->value => $writable && $permissions->filesLor,
            ],
            // Section 1 of the portal's form, shown read-only to a viewer who may file: the PDF is rendered from the
            // same roster rows, never from what the browser sends back.
            'formParties' => $files !== [] ? $this->formParties->for($trooper, $user) : null,
        ];
    }

    /**
     * The section practices a staff member attended (#448), with what this viewer may do: enter one (the staff member,
     * their staff leader, an Admin) and remove an entry. Null for someone who is not staff and has no entries, so the
     * panel only shows where it means something. Nothing is offered during a view-as preview, since writes are refused
     * then (AD-9). The form offers every section of the regiment and the last weeks down to the floor.
     *
     * @return array<string, mixed>|null
     */
    private function staffPractices(User $user, Trooper $trooper): ?array
    {
        $staff = $this->staffAccess->staffUnit($trooper) !== null;
        $entries = $this->staffHistory->for($trooper, $staff);

        if (! $staff && $entries === []) {
            return null;
        }

        $writable = $this->scope->activeViewAs($user) === null;
        $canRecord = $writable && $this->staffAccess->mayRecord($user, $trooper);
        $canRemove = $writable && $this->staffAccess->mayRemove($user, $trooper);
        $floor = WeekOf::floor();

        $weeks = [];
        for ($week = WeekOf::start(CarbonImmutable::now()); count($weeks) < self::STAFF_PRACTICE_WEEKS && $week->greaterThanOrEqualTo($floor); $week = $week->subWeek()) {
            $weeks[] = $week->toDateString();
        }

        return [
            'entries' => array_map(fn (array $entry) => [
                ...$entry,
                'removable' => $canRemove && $entry['source'] === 'entered' && $entry['week'] >= $floor->toDateString(),
            ], $entries),
            'canRecord' => $canRecord,
            // Sections with troopers only (#442), as every other picker: an empty one has no practice to attend.
            'sections' => $canRecord ? $this->headcount->withoutEmptyLineUnits($this->units->tree()
                ->filter(fn (Unit $unit) => $unit->type === UnitType::Section))
                ->map(fn (Unit $unit) => ['id' => $unit->id, 'label' => $unit->callsign ?? $unit->name])
                ->values()
                ->all() : [],
            'weeks' => $canRecord ? $weeks : [],
        ];
    }

    /**
     * The weeks the trooper was marked missing a section practice in the current KPI window, oldest first. None
     * for a trooper the sync has not placed: they have no section practice to miss.
     *
     * @return list<string>
     */
    private function missedWeeks(Trooper $trooper): array
    {
        $section = $trooper->primary_section_id === null ? null : $this->units->find($trooper->primary_section_id);

        if ($section === null) {
            return [];
        }

        return $this->kpis->trooperAttendance($section, WeekOf::start(CarbonImmutable::now()))->get($trooper->id)?->missedWeeks ?? [];
    }
}
