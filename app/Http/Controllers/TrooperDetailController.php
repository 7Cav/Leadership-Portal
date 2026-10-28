<?php

namespace App\Http\Controllers;

use App\Authorization\CounselingAccess;
use App\Authorization\ScopeResolver;
use App\Kpi\AttendanceHistory;
use App\Kpi\KpiService;
use App\Milpac\CounselingHistory;
use App\Milpac\TrooperMilpac;
use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Models\Trooper;
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
    ) {}

    public function show(Request $request, Trooper $trooper): Response
    {
        $user = $request->user();

        abort_unless($this->scope->canSee($user, $trooper), 404);

        return Inertia::render('TrooperDetail', [
            ...$this->milpac->for($trooper, $this->scope->resolve($user), $this->missedWeeks($trooper)),
            // Their attendance across every unit they were in (2026-10-27): anyone who may see the trooper now sees
            // the weeks from before a transfer too. Past weekly reports stay with the unit that sent them.
            'attendance' => $this->history->forTrooper($trooper, WeekOf::start(CarbonImmutable::now())),
            'counseling' => $this->counseling($user, $trooper),
        ]);
    }

    /**
     * The DCSs and LORs this viewer may see (#381), with what they may do: file either type, and change each record.
     * During a view-as preview nothing is offered, since every write is refused then (AD-9).
     *
     * @return array<string, mixed>
     */
    private function counseling(User $user, Trooper $trooper): array
    {
        $permissions = $this->access->for($user, $trooper);
        $writable = $this->scope->activeViewAs($user) === null;

        return [
            ...$this->counselingHistory->for(
                $trooper,
                $permissions->seesDcs,
                $permissions->seesLor,
                fn (CounselingRecord $record) => $writable && $permissions->changes($record),
            ),
            'canFile' => [
                CounselingType::Dcs->value => $writable && $permissions->filesDcs,
                CounselingType::Lor->value => $writable && $permissions->filesLor,
            ],
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
