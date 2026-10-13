<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Kpi\KpiService;
use App\Kpi\Ratio;
use App\Kpi\TrooperAttendance;
use App\Kpi\UnitKpis;
use App\Models\SyncRun;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\CountingMark;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The roster (Stories 2.8, 4.6, 4.7, 4.9): one page showing the selected unit's whole subtree as
 * an order of battle or a 20-week attendance matrix, plus the trooper detail its Sheet loads.
 *
 * Every read is gated by the single ScopeResolver (AD-8) — a unit or trooper outside the
 * effective scope is a 403, never a quietly empty page. KPIs and the at-risk flag come from the
 * shared `KpiService` (AD-21); this controller only groups its per-trooper rows by unit, never
 * re-derives one. Reads are batched over the whole subtree, so a battalion costs the same
 * handful of queries as a section.
 */
class RosterController extends Controller
{
    /** Cav weeks the attendance matrix spans, ending with the current one (4.7). */
    public const HISTORY_WEEKS = 20;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly AttendanceRepository $attendance,
        private readonly UnitCommandMappingRepository $commands,
        private readonly SyncRunRepository $syncRuns,
        private readonly KpiService $kpis,
    ) {}

    public function index(Request $request, ?Unit $unit = null): Response
    {
        $user = $request->user();
        $scope = $this->scope->resolve($user);
        $tree = $this->units->tree();

        if ($unit !== null && ! $scope->contains($unit->id)) {
            abort(403, 'That unit is outside your scope.');
        }

        $unit ??= $this->defaultUnit($user, $tree, $scope);
        $week = WeekOf::start(CarbonImmutable::now());

        return Inertia::render('Roster', [
            'unit' => $unit ? $this->unitSummary($unit) : null,
            'trail' => $unit ? $this->trail($unit, $tree, $scope) : [],
            'scopeOptions' => $this->scopeOptions($tree, $scope),
            'weeks' => $this->weeks($week),
            'currentWeek' => $week->toDateString(),
            ...($unit ? $this->roster($unit, $week) : ['units' => [], 'troopers' => []]),
            'sync' => $this->syncStatus($unit, $user),
            'can' => [
                // Follows view-as: a preview of a non-Admin never offers an Admin action (1.13).
                'sync' => $this->scope->actsAsAdmin($user),
                // A leader refreshing their own echelon (the whole roster stays Admin-only).
                'syncUnit' => $unit !== null && $this->scope->commandsUnit($user, $unit),
            ],
            'links' => [
                'milpac' => Trooper::MILPAC_PROFILE_URL,
                // The in-app milpac page (Story 6.3); the Sheet appends the trooper's id.
                'trooper' => '/troopers/',
                // `rollcall.show` belongs to the rollcall screen (3.7); until it ships the Sheet
                // offers no link rather than one that 404s.
                'rollcall' => Route::has('rollcall.show') ? '/rollcall/' : null,
            ],
        ]);
    }

    /**
     * One trooper's detail for the roster Sheet: identity, section and leader, KPIs, and the
     * 20-week marks with their source. In scope only if their primary section is.
     */
    public function trooper(Request $request, int $trooper): JsonResponse
    {
        $found = $this->troopers->find($trooper);

        // One answer for "no such trooper", "not placed" and "outside your scope" (AD-8): 404, the same gate the
        // milpac page uses, so ids cannot be enumerated. The Sheet needs a section, so an unplaced trooper has none to show.
        if ($found === null || $found->primary_section_id === null || ! $this->scope->canSee($request->user(), $found)) {
            abort(404);
        }

        $section = $this->units->find($found->primary_section_id);
        $week = WeekOf::start(CarbonImmutable::now());
        $kpis = $this->kpis->trooperAttendance($section, $week)->get($found->id);
        $marks = $this->historyMarks($section, $week)->get($found->id, collect());
        $leader = $this->commands->commandersOf([$section->id])->get($section->id);

        return response()->json([
            'trooper' => [
                ...$this->trooperRow($found, $kpis, $marks, $week, $leader),
                'rankFull' => $found->rank,
                'mos' => $found->mos,
                'tig' => $found->tig?->toDateString(),
                'section' => $this->unitSummary($section),
                'sectionLeader' => $leader ? $this->person($leader) : null,
                'milpacUrl' => Trooper::MILPAC_PROFILE_URL.$found->cav_id,
            ],
            'weeks' => $this->weeks($week),
            'currentWeek' => $week->toDateString(),
        ]);
    }

    /**
     * The unit to open when none is named: the one the user's roster billet commands, when it is
     * still in scope (a view-as preview may have narrowed it away), else the top of their scope.
     * A regiment-wide scope lands on its first battalion instead — a whole regiment is too much
     * to read at once, and the switcher is right there to widen it.
     *
     * @param  Collection<int, Unit>  $tree
     * @param  Collection<int, int>  $scope
     */
    private function defaultUnit(User $user, Collection $tree, Collection $scope): ?Unit
    {
        $commanded = $this->commands->commandedUnitFor($user);

        if ($commanded !== null && $scope->contains($commanded->id)) {
            return $commanded;
        }

        $top = $tree->filter(fn (Unit $u) => $scope->contains($u->id))
            ->sortBy(fn (Unit $u) => [substr_count($u->path, '/'), $u->id])
            ->first();

        if ($top?->type === UnitType::Regiment) {
            return $tree->filter(fn (Unit $u) => $u->parent_id === $top->id && $scope->contains($u->id))
                ->sortBy('id')
                ->first() ?? $top;
        }

        return $top;
    }

    /**
     * @return array{units: list<array<string, mixed>>, troopers: list<array<string, mixed>>}
     */
    private function roster(Unit $unit, CarbonImmutable $week): array
    {
        $subtree = $this->depthFirst($this->units->subtree($unit), $unit);
        $placed = $this->troopers->placedWithin($unit);
        $attendance = $this->kpis->trooperAttendance($unit, $week);
        $marks = $this->historyMarks($unit, $week);
        $leaders = $this->commands->commandersOf($subtree->pluck('id')->all());

        $paths = $subtree->mapWithKeys(fn (Unit $u) => [$u->id => $u->path]);
        $sectionOf = $placed->mapWithKeys(fn (Trooper $t) => [$t->id => $t->primary_section_id]);

        $units = $subtree->map(function (Unit $u) use ($attendance, $sectionOf, $paths, $leaders) {
            // The unit's troopers are those whose section sits anywhere under it.
            $within = $attendance->filter(function (TrooperAttendance $row) use ($sectionOf, $paths, $u) {
                $section = $sectionOf->get($row->trooperId);

                return $section !== null && str_starts_with($paths->get($section, ''), $u->path);
            });

            return [
                ...$this->unitSummary($u),
                'parentId' => $u->parent_id,
                'leader' => ($leader = $leaders->get($u->id)) ? $this->person($leader) : null,
                'kpis' => $this->kpiProps($this->kpis->sum($within)),
            ];
        })->values()->all();

        $troopers = $placed->map(fn (Trooper $t) => $this->trooperRow(
            $t,
            $attendance->get($t->id),
            $marks->get($t->id, collect()),
            $week,
            $leaders->get($t->primary_section_id),
        ))->values()->all();

        return ['units' => $units, 'troopers' => $troopers];
    }

    /**
     * @param  Collection<int, CountingMark>  $marks  this trooper's marks over the history window
     * @return array<string, mixed>
     */
    private function trooperRow(Trooper $t, ?TrooperAttendance $kpis, Collection $marks, CarbonImmutable $week, ?Trooper $sectionLeader): array
    {
        $byWeek = $marks->keyBy(fn (CountingMark $m) => $m->weekStart->toDateString());

        return [
            'id' => $t->id,
            'cavId' => $t->cav_id,
            'name' => $t->username,
            // `SGT Vasquez.R`, or `SGT #1234` with no forum account linked (2.10).
            'displayName' => $t->display_name,
            'rank' => $t->rank_short ?? $t->rank,
            'rankFull' => $t->rank,
            'billet' => $t->billet,
            'status' => $t->status->value,
            'departed' => $t->status === TrooperStatus::Inactive,
            'sectionId' => $t->primary_section_id,
            'role' => $t->roleUnder($sectionLeader),
            'joined' => $t->tis?->toDateString(),
            // Null for a departed trooper: they are no longer measured (AD-21 strength).
            'kpis' => $kpis ? [
                'rc' => $this->ratio($kpis->rollcall),
                'sp' => $this->ratio($kpis->practice),
                'missed' => $kpis->missed,
                'atRisk' => $kpis->atRisk(),
                'onLoa' => $kpis->onLoa,
            ] : null,
            // One entry per history week, oldest first; null where the section had no practice
            // row that week (no rollcall posted, no SP held).
            'weeks' => array_map(function (string $date) use ($byWeek) {
                $mark = $byWeek->get($date);

                return $mark === null ? null : [
                    'rollcall' => $mark->rollcall->value,
                    'status' => $mark->status?->value,
                    'source' => $mark->source?->value,
                    'held' => $mark->held,
                ];
            }, $this->weeks($week)),
        ];
    }

    /**
     * Each trooper's counting marks over the history window, in one query for the subtree —
     * departed troopers included, so their history shows rather than reading as empty weeks.
     *
     * @return Collection<int, Collection<int, CountingMark>>
     */
    private function historyMarks(Unit $unit, CarbonImmutable $week): Collection
    {
        return $this->attendance
            ->countingMarks($unit, $week->subWeeks(self::HISTORY_WEEKS - 1), $week, includeDeparted: true)
            ->groupBy(fn (CountingMark $m) => $m->trooperId);
    }

    /**
     * @return list<string> the history window's week starts, oldest first
     */
    private function weeks(CarbonImmutable $week): array
    {
        return array_map(
            fn (int $back) => $week->subWeeks($back)->toDateString(),
            range(self::HISTORY_WEEKS - 1, 0),
        );
    }

    /**
     * Source and freshness of the cached roster (AD-20): the time shown is always the last
     * *successful* sync; a later failed or partial run marks the cache stale (AD-4).
     *
     * @return array<string, mixed>
     */
    private function syncStatus(?Unit $unit, User $user): array
    {
        $success = $this->syncRuns->latestSuccessful(SyncRun::SOURCE_ROSTER);
        $latest = $this->syncRuns->latestFinished(SyncRun::SOURCE_ROSTER);
        $stale = $latest !== null && $latest->id !== $success?->id;

        return [
            'source' => 'milpac',
            'lastSuccessAt' => $success?->finished_at?->toIso8601String(),
            'stale' => $stale,
            'lastAttempt' => $stale ? [
                'status' => $latest->status->value,
                'at' => $latest->finished_at?->toIso8601String(),
            ] : null,
            // The unit leader's own last sync of this echelon, kept apart from the roster-wide stamp above.
            'unit' => $unit !== null && $this->scope->commandsUnit($user, $unit) ? $this->unitSyncStatus($unit) : null,
        ];
    }

    /**
     * @return array{status: string, at: ?string}|null
     */
    private function unitSyncStatus(Unit $unit): ?array
    {
        $run = $this->syncRuns->latestForUnit(SyncRun::SOURCE_ROSTER_UNIT, $unit->id);

        return $run === null ? null : ['status' => $run->status->value, 'at' => $run->finished_at?->toIso8601String()];
    }

    /**
     * The unit's ancestors and itself, for the breadcrumb. Ancestors outside scope are shown but
     * not linkable.
     *
     * @param  Collection<int, Unit>  $tree
     * @param  Collection<int, int>  $scope
     * @return list<array<string, mixed>>
     */
    private function trail(Unit $unit, Collection $tree, Collection $scope): array
    {
        $ids = array_map('intval', array_filter(explode('/', $unit->path)));
        $byId = $tree->keyBy('id');

        return collect($ids)
            ->map(fn (int $id) => $byId->get($id))
            ->filter()
            ->map(fn (Unit $u) => [...$this->unitSummary($u), 'inScope' => $scope->contains($u->id)])
            ->values()
            ->all();
    }

    /**
     * Every unit in scope, depth-first, for the scope switcher.
     *
     * @param  Collection<int, Unit>  $tree
     * @param  Collection<int, int>  $scope
     * @return list<array<string, mixed>>
     */
    private function scopeOptions(Collection $tree, Collection $scope): array
    {
        $roots = $tree->filter(fn (Unit $u) => $scope->contains($u->id) && ! $scope->contains($u->parent_id));

        return $roots->sortBy('id')
            ->flatMap(fn (Unit $root) => $this->depthFirst($tree, $root))
            ->map(fn (Unit $u) => [...$this->unitSummary($u), 'depth' => substr_count($u->path, '/') - 1])
            ->values()
            ->all();
    }

    /**
     * `$root` and its descendants, depth-first with siblings in id (creation) order — `path`
     * order would sort "10/" before "9/".
     *
     * @param  Collection<int, Unit>  $tree
     * @return Collection<int, Unit>
     */
    private function depthFirst(Collection $tree, Unit $root): Collection
    {
        $children = $tree->groupBy('parent_id');
        $walk = function (Unit $u) use (&$walk, $children): array {
            return [$u, ...$children->get($u->id, collect())->sortBy('id')->flatMap(fn (Unit $c) => $walk($c))->all()];
        };

        return collect($walk($tree->firstWhere('id', $root->id) ?? $root));
    }

    /**
     * @return array<string, mixed>
     */
    private function unitSummary(Unit $u): array
    {
        return ['id' => $u->id, 'type' => $u->type->value, 'name' => $u->name, 'callsign' => $u->callsign];
    }

    /**
     * @return array<string, mixed>
     */
    private function person(Trooper $t): array
    {
        return ['id' => $t->id, 'cavId' => $t->cav_id, 'rank' => $t->rank_short ?? $t->rank, 'name' => $t->username, 'displayName' => $t->display_name];
    }

    /**
     * @return array<string, mixed>
     */
    private function kpiProps(UnitKpis $k): array
    {
        return [
            'strength' => $k->strength,
            'rc' => $this->ratio($k->rc30d),
            'sp' => $this->ratio($k->sp30d),
            'atRisk' => $k->atRisk,
            'onLoa' => $k->onLoa,
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function ratio(Ratio $r): array
    {
        return [$r->numerator, $r->denominator];
    }
}
