<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Authorization\StaffPracticeAccess;
use App\Commands\Units\SaveUnitGuidonHandler;
use App\Kpi\AutoExcused;
use App\Kpi\Headcount;
use App\Kpi\KpiService;
use App\Kpi\PracticeGuest;
use App\Kpi\PracticeGuests;
use App\Kpi\Ratio;
use App\Kpi\TrooperAttendance;
use App\Kpi\UnitKpis;
use App\Loa\LoaProvenance;
use App\Loa\LoaSyncProblems;
use App\Loa\UpcomingLeave;
use App\Milpac\BilletTags;
use App\Milpac\PlacementHistory;
use App\Milpac\RosterProvenance;
use App\Milpac\StaffPracticeHistory;
use App\Models\SyncRun;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitGuidon;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\CountingMark;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitActingLeadRepository;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitGuidonRepository;
use App\Repositories\UnitLeadRepository;
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
        private readonly PracticeGuests $practiceGuests,
        private readonly UnitLeadRepository $leads,
        private readonly UnitActingLeadRepository $actingLeads,
        private readonly PlacementHistory $placementHistory,
        private readonly Headcount $headcount,
        private readonly BilletTags $billetTags,
        private readonly UnitGuidonRepository $guidons,
        private readonly StaffPracticeAccess $staffAccess,
        private readonly StaffPracticeHistory $staffHistory,
        private readonly UpcomingLeave $upcomingLeave,
        private readonly LoaSyncProblems $loaSyncProblems,
        private readonly AutoExcused $autoExcused,
    ) {}

    /**
     * Every saved guidon by unit id, read once per request. Each action resets it first: the route keeps this
     * controller between requests, so a stale copy would otherwise outlive the request that read it.
     *
     * @var Collection<int, UnitGuidon>|null
     */
    private ?Collection $guidonsByUnit = null;

    public function index(Request $request, ?int $unit = null): Response
    {
        $this->guidonsByUnit = null;
        $user = $request->user();
        $scope = $this->scope->resolve($user);
        $tree = $this->units->tree();
        // The order of battle, the unit picker and the default unit leave out a line unit with nobody in it or under it
        // (#442). One opened by id still renders, as the unit asked for.
        $shown = $this->headcount->withoutEmptyLineUnits($tree);

        // One answer for "no such unit" and "outside your scope" (AD-8), so unit ids cannot be probed.
        $selected = $unit === null
            ? $this->defaultUnit($user, $shown, $scope)
            : ($scope->contains($unit) ? $tree->firstWhere('id', $unit) : null);
        abort_if($unit !== null && $selected === null, 403, 'That unit is outside your scope.');

        $week = WeekOf::start(CarbonImmutable::now());
        // The unit sync is a write, and a view-as preview changes nothing (1.13), so a preview never offers it.
        $syncsUnit = $selected !== null && $this->scope->activeViewAs($user) === null && $this->scope->commandsUnit($user, $selected);
        // The guidon generator (#446) is a write too: a company or above that the viewer commands, never in a preview.
        $editsGuidon = $syncsUnit && in_array($selected->type, SaveUnitGuidonHandler::TYPES, true);

        return Inertia::render('Roster', [
            'unit' => $selected ? $this->unitSummary($selected) : null,
            'trail' => $selected ? $this->trail($selected, $tree, $scope) : [],
            'scopeOptions' => $this->scopeOptions($shown, $scope),
            'weeks' => $this->weeks($week),
            'currentWeek' => $week->toDateString(),
            ...($selected ? $this->roster($selected, $week, $shown) : ['units' => [], 'troopers' => []]),
            'guests' => $selected ? $this->guestProps($selected, $week, $tree) : null,
            // Source and freshness of the cached roster (AD-20): the one rule every roster page shares, plus the unit
            // leader's own last sync of this echelon, kept apart from the roster-wide stamp.
            'sync' => [
                ...$this->rosterSync->current()->toArray(),
                'unit' => $syncsUnit ? $this->unitSyncStatus($selected) : null,
            ],
            // The matrix's LOA weeks and the Sheet's "On LOA" and at-risk move with leave: where it comes from and
            // when it last synced (4.3, AD-20).
            'loaSync' => $this->loaProvenance->current()->toArray(),
            // What the latest LOA sync could not read, for an Admin (follows view-as, like `can.sync`). There is no
            // leave sync button yet, so this sits beside the leave note on the page that holds the roster sync.
            'loaSyncProblems' => $this->scope->actsAsAdmin($user) ? $this->loaSyncProblems->latest() : null,
            'can' => [
                // Follows view-as: a preview of a non-Admin never offers an Admin action (1.13).
                'sync' => $this->scope->actsAsAdmin($user),
                // A leader refreshing their own echelon (the whole roster stays Admin-only).
                'syncUnit' => $syncsUnit,
                // Make and save this unit's guidon (#446).
                'editGuidon' => $editsGuidon,
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
        $this->guidonsByUnit = null;
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
        // The unit's lead as its roster header names it, acting leads included, and the trooper's own billet tag (#443).
        $lead = $this->leads->leadsOf([$section->id])->get($section->id);
        $row = $this->trooperRow($found, $kpis, $marks, $week, $this->billetTags->of(collect([$found]))->get($found->id), $this->excusedWeeks([$found->id], $week)[$found->id] ?? []);
        // Each week's section by name, for the weeks they were somewhere else (2026-10-27).
        $labels = $marks->pluck('sectionId')->filter()->unique()
            ->mapWithKeys(fn (int $id) => [$id => ($unit = $this->units->find($id)) === null ? null : ($unit->callsign ?? $unit->name)]);
        $row['weeks'] = array_map(fn (?array $w) => $w === null ? null : [...$w, 'section' => $labels->get($w['sectionId'])], $row['weeks']);

        return response()->json([
            'trooper' => [
                ...$row,
                'rankFull' => $found->rank,
                'mos' => $found->mos,
                'section' => $this->unitSummary($section),
                'sectionLeader' => $lead ? [...$this->person($lead->trooper), 'source' => $lead->source] : null,
                'milpacUrl' => Trooper::MILPAC_PROFILE_URL.$found->cav_id,
                // The weeks behind `kpis.missed`, oldest first, for the Sheet's makeup PM link.
                'missedWeeks' => $kpis?->missedWeeks ?? [],
                // Their moves between units, newest first (2026-10-27).
                'transfers' => $this->placementHistory->transfersOf($found->id),
                // The section practices a staff member attended (#448), read only here; entered on the milpac.
                'staffPractices' => $this->staffHistory->for($found, $this->staffAccess->staffUnit($found) !== null),
                // The first day of a leave starting next Cav week, for the "Leave from" chip; null with none (read-only).
                'leaveFrom' => $this->upcomingLeave->startingAfter([$found->id], $week)[$found->id] ?? null,
            ],
            'weeks' => $this->weeks($week),
            'currentWeek' => $week->toDateString(),
        ]);
    }

    /**
     * The unit to open when none is named: the one the user's roster billet commands, when it is
     * still in scope (a view-as preview may have narrowed it away), else the unit they are acting
     * lead of (the first in tree order when they act for several, the order the org page lists
     * them in), else the top of their scope. In a person preview the billet and acting leads are
     * the previewed person's, so the preview opens where they would (#426); a unit preview opens
     * at its unit.
     * A regiment-wide scope lands on its first battalion instead — a whole regiment is too much
     * to read at once, and the switcher is right there to widen it.
     *
     * @param  Collection<int, Unit>  $tree
     * @param  Collection<int, int>  $scope
     */
    private function defaultUnit(User $user, Collection $tree, Collection $scope): ?Unit
    {
        $account = $this->scope->landingAccount($user);
        $commanded = $account === null ? null : $this->commands->commandedUnitFor($account);

        if ($commanded !== null && $scope->contains($commanded->id)) {
            return $commanded;
        }

        $actingFor = ($account === null ? collect() : $this->actingLeads->unitsLedBy($account))->pluck('id')->flip();
        $acting = $tree->first(fn (Unit $u) => $actingFor->has($u->id) && $scope->contains($u->id));

        if ($acting !== null) {
            return $acting;
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
     * @param  Collection<int, Unit>  $shown  the tree without its empty line units (#442)
     * @return array{units: list<array<string, mixed>>, troopers: list<array<string, mixed>>}
     */
    private function roster(Unit $unit, CarbonImmutable $week, Collection $shown): array
    {
        $shownIds = $shown->pluck('id')->flip();
        $subtree = $this->depthFirst(
            $this->units->subtree($unit)->filter(fn (Unit $u) => $u->id === $unit->id || $shownIds->has($u->id)),
            $unit,
        );
        $placed = $this->troopers->placedWithin($unit);
        $attendance = $this->kpis->trooperAttendance($unit, $week);
        $marks = $this->historyMarks($unit, $week);
        $excused = $this->excusedWeeks($placed->modelKeys(), $week);
        // The unit headers name the unit's lead, acting leads and portfolio officers included (2026-10-25); each
        // trooper's tag is the command billet they hold in their unit, or their acting lead (#443).
        $tags = $this->billetTags->of($placed);
        $unitLeads = $this->leads->leadsOf($subtree->pluck('id')->all());

        $paths = $subtree->mapWithKeys(fn (Unit $u) => [$u->id => $u->path]);
        $sectionOf = $placed->mapWithKeys(fn (Trooper $t) => [$t->id => $t->primary_section_id]);
        // Strength on the roster is the headcount (2026-10-24), the one definition in {@see Headcount}. RC and SP stay the
        // measured sections' (AD-21).
        $headcounts = $this->headcount->perUnit(
            $subtree,
            $placed->filter(fn (Trooper $t) => $t->status->isServing())->countBy('primary_section_id'),
        );

        $units = $subtree->map(function (Unit $u) use ($attendance, $sectionOf, $paths, $unitLeads, $headcounts) {
            // The unit's troopers are those whose section sits anywhere under it.
            $within = $attendance->filter(function (TrooperAttendance $row) use ($sectionOf, $paths, $u) {
                $section = $sectionOf->get($row->trooperId);

                return $section !== null && str_starts_with($paths->get($section, ''), $u->path);
            });

            return [
                ...$this->unitSummary($u),
                'parentId' => $u->parent_id,
                'leader' => ($lead = $unitLeads->get($u->id)) ? [...$this->person($lead->trooper), 'source' => $lead->source] : null,
                'kpis' => [...$this->kpiProps($this->kpis->sum($within)), 'strength' => $headcounts->get($u->id, 0)],
            ];
        })->values()->all();

        $troopers = $placed->map(fn (Trooper $t) => $this->trooperRow(
            $t,
            $attendance->get($t->id),
            $marks->get($t->id, collect()),
            $week,
            $tags->get($t->id),
            $excused[$t->id] ?? [],
        ))->values()->all();

        return ['units' => $units, 'troopers' => $troopers];
    }

    /**
     * "Guests at our practices" (JSAM evidence, decided 2026-10-23) for a section, platoon or company: the troopers from
     * outside the unit recorded at its sections' practices over the JSAM window, read from {@see PracticeGuests} (own SP
     * from the one KPI definition, AD-21, never here), with the thresholds so the panel can say what a candidate is.
     * Null for a battalion or the regiment, whose commanders are not the "benefitted combat line unit commander" the
     * award names, and whose lists would be too long to read. Read-only, like the rest of the roster: a staff grant and a
     * view-as preview see the same panel and nothing on it writes.
     *
     * @param  Collection<int, Unit>  $tree
     * @return array{criteria: array{minVisits: int, minOwnSpPercent: int, windowWeeks: int}, rows: list<array<string, mixed>>}|null
     */
    private function guestProps(Unit $unit, CarbonImmutable $week, Collection $tree): ?array
    {
        if (! in_array($unit->type, [UnitType::Section, UnitType::Platoon, UnitType::Company], true)) {
            return null;
        }

        $units = $tree->keyBy('id');

        return [
            'criteria' => $this->practiceGuests->criteria()->toArray(),
            'rows' => array_map(fn (PracticeGuest $guest) => [
                'trooperId' => $guest->trooper->id,
                'name' => $guest->trooper->username,
                'displayName' => $guest->trooper->display_name,
                'rank' => $guest->trooper->rank_short ?? $guest->trooper->rank,
                'rankFull' => $guest->trooper->rank,
                'section' => ($section = $units->get($guest->trooper->primary_section_id)) instanceof Unit
                    ? ['id' => $section->id, 'designation' => $section->callsign ?? $section->name]
                    : null,
                'visits' => $guest->visits,
                'lastSeen' => $guest->lastWeek->toDateString(),
                'ownSp' => $this->ratio($guest->ownSp),
                'candidate' => $guest->candidate,
            ], $this->practiceGuests->forUnit($unit, $week)),
        ];
    }

    /**
     * @param  Collection<int, CountingMark>  $marks  this trooper's marks over the history window
     * @param  array<string, string>  $excused  their automatically excused history weeks ({@see AutoExcused})
     * @return array<string, mixed>
     */
    private function trooperRow(Trooper $t, ?TrooperAttendance $kpis, Collection $marks, CarbonImmutable $week, ?string $role, array $excused = []): array
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
            // ELOA's "was" (2026-10-24): the line unit they left, when the sync saw them move.
            'was' => $t->relationLoaded('lastLineUnit') && $t->lastLineUnit !== null ? ($t->lastLineUnit->callsign ?? $t->lastLineUnit->name) : null,
            // The short name of the command billet they hold in their unit (`CO`, `PSG`, `SL`...), `Acting PL` and the
            // like for an acting lead, else null ({@see BilletTags}, #443).
            'role' => $role,
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
                    // The section whose practice this was: after a transfer, an earlier week is their old section's.
                    'sectionId' => $mark->sectionId,
                ];
            }, $this->weeks($week)),
            // The history weeks excused automatically (2026-10-30), `Y-m-d` => `recruit` or `transfer`: the cell shows
            // Excused on whichever half nobody recorded.
            'excusedWeeks' => (object) $excused,
        ];
    }

    /**
     * The automatically excused weeks ({@see AutoExcused}) of these troopers over the history window.
     *
     * @param  list<int>  $trooperIds
     * @return array<int, array<string, string>>
     */
    private function excusedWeeks(array $trooperIds, CarbonImmutable $week): array
    {
        return $this->autoExcused->forTroopersOverWeeks($trooperIds, $week->subWeeks(self::HISTORY_WEEKS - 1), self::HISTORY_WEEKS);
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
        return [
            'id' => $u->id,
            'type' => $u->type->value,
            'kind' => ($u->kind ?? UnitKind::Line)->value,
            'name' => $u->name,
            'callsign' => $u->callsign,
            'label' => $u->label,
            // The unit's saved guidon design (#446), drawn next to its name; null when it has none.
            'guidon' => $this->guidonOf($u),
        ];
    }

    /**
     * @return array{primary: string, secondary: string, topLeft: string, bottomRight: string}|null
     */
    private function guidonOf(Unit $u): ?array
    {
        $this->guidonsByUnit ??= $this->guidons->all();

        return $this->guidonsByUnit->get($u->id)?->design();
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
