<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Kpi\KpiService;
use App\Kpi\Ratio;
use App\Kpi\TrooperAttendance;
use App\Kpi\UnitKpis;
use App\Loa\LoaProvenance;
use App\Milpac\RosterProvenance;
use App\Models\SyncRun;
use App\Models\Trooper;
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
        private readonly RosterProvenance $rosterSync,
        private readonly KpiService $kpis,
        private readonly LoaProvenance $loaProvenance,
    ) {}

    public function index(Request $request, ?int $unit = null): Response
    {
        $user = $request->user();
        $scope = $this->scope->resolve($user);
        $tree = $this->units->tree();

        // One answer for "no such unit" and "outside your scope" (AD-8), so unit ids cannot be probed.
        $selected = $unit === null
            ? $this->defaultUnit($user, $tree, $scope)
            : ($scope->contains($unit) ? $tree->firstWhere('id', $unit) : null);
        abort_if($unit !== null && $selected === null, 403, 'That unit is outside your scope.');

        $week = WeekOf::start(CarbonImmutable::now());
        // The unit sync is a write, and a view-as preview changes nothing (1.13), so a preview never offers it.
        $syncsUnit = $selected !== null && $this->scope->activeViewAs($user) === null && $this->scope->commandsUnit($user, $selected);

        return Inertia::render('Roster', [
            'unit' => $selected ? $this->unitSummary($selected) : null,
            'trail' => $selected ? $this->trail($selected, $tree, $scope) : [],
            'scopeOptions' => $this->scopeOptions($tree, $scope),
            'weeks' => $this->weeks($week),
            'currentWeek' => $week->toDateString(),
            ...($selected ? $this->roster($selected, $week) : ['units' => [], 'troopers' => []]),
            // Source and freshness of the cached roster (AD-20): the one rule every roster page shares, plus the unit
            // leader's own last sync of this echelon, kept apart from the roster-wide stamp.
            'sync' => [
                ...$this->rosterSync->current()->toArray(),
                'unit' => $syncsUnit ? $this->unitSyncStatus($selected) : null,
            ],
            // The matrix's LOA weeks and the Sheet's "On LOA" and at-risk move with leave: where it comes from and
            // when it last synced (4.3, AD-20).
            'loaSync' => $this->loaProvenance->current()->toArray(),
            'can' => [
                // Follows view-as: a preview of a non-Admin never offers an Admin action (1.13).
                'sync' => $this->scope->actsAsAdmin($user),
                // A leader refreshing their own echelon (the whole roster stays Admin-only).
                'syncUnit' => $syncsUnit,
            ],
            'links' => [
                'milpac' => Trooper::MILPAC_PROFILE_URL,
                // The in-app milpac page (Story 6.3); the Sheet appends the trooper's id.
                'trooper' => '/troopers/',
                // The rollcall screen (3.7); the Sheet appends the trooper's section id.
                'rollcall' => '/rollcall/',
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
                'section' => $this->unitSummary($section),
                'sectionLeader' => $leader ? $this->person($leader) : null,
                'milpacUrl' => Trooper::MILPAC_PROFILE_URL.$found->cav_id,
                // The weeks behind `kpis.missed`, oldest first, for the Sheet's makeup PM link.
                'missedWeeks' => $kpis?->missedWeeks ?? [],
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
            'departed' => ! $t->status->isServing(),
            'sectionId' => $t->primary_section_id,
            'role' => $t->roleUnder($sectionLeader),
            'joined' => $t->tis?->toDateString(),
            // Time in grade: the date of the trooper's last promotion, for the order of battle's TIG column and sort.
            'tig' => $t->tig?->toDateString(),
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
