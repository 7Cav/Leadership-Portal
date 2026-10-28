<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Kpi\ChainNode;
use App\Kpi\ChildReport;
use App\Kpi\ChildReportStatus;
use App\Kpi\Headcount;
use App\Kpi\KpiService;
use App\Kpi\ReportChain;
use App\Kpi\TrooperAttendance;
use App\Kpi\UnitKpis;
use App\Loa\LoaCover;
use App\Loa\LoaCoverage;
use App\Loa\LoaProvenance;
use App\Milpac\RosterProvenance;
use App\Models\Unit;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use ErrorException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use TypeError;

/**
 * The authenticated landing (Story 1.4), the weekly-cycle home (Story 1.15) and the leader's
 * dashboard (Stories 5.9–5.11, mockups/v2/shell-f.html): the calendar of the running report cycle,
 * and for the viewer's scope the six KPI tiles with their trend, what needs attention, and the
 * direct subordinate units.
 *
 * Read-only. Scope comes from the ScopeResolver on every request, never from `is_admin` (AD-8;
 * view-as narrows it, AD-9). The numbers come from the shared KPI pieces (AD-21) and sent
 * snapshots; nothing is derived here, and a scope with nothing to show gets `null`/empty props so
 * the page draws an empty state rather than a number the portal does not have.
 */
class HomeController extends Controller
{
    /** Whole past weeks a sparkline spans: the trailing 4 Cav weeks of a "30d" KPI (AD-21). */
    private const SPARKLINE_WEEKS = 4;

    /** Most items one worklist group lists; the rest are counted, not drawn. */
    private const WORKLIST_LIMIT = 10;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly WeeklyReportRepository $reports,
        private readonly RosterProvenance $rosterSync,
        private readonly LoaProvenance $loaProvenance,
        private readonly TrooperRepository $troopers,
        private readonly SectionPracticeRepository $practices,
        private readonly AttendanceRepository $attendance,
        private readonly KpiService $kpis,
        private readonly ReportChain $chain,
        private readonly LoaCoverage $loaCoverage,
        private readonly Headcount $headcount,
    ) {}

    public function show(Request $request): Response
    {
        // The week whose report cycle is running: the closing week stays current until its report
        // deadline passes, so the deadline counted down is never one that already went by.
        $weekStart = WeekOf::reporting(CarbonImmutable::now());
        $scope = $this->scope->resolve($request->user());
        $tree = $this->units->tree();
        // The week's reports across the scope, read once: which units sent decides which take part in the week.
        $reports = $this->reports->forUnits($scope->values()->all(), $weekStart);
        $sent = $reports->filter(fn (WeeklyReport $report) => $report->status === WeeklyReportStatus::Sent)->keys()->all();
        // A line unit with nobody in it or under it, now or in the week, is left out of the whole page (#442): the
        // tiles, the unit table, the step matrix and what needs attention.
        $roots = $this->headcount->withTroopersOrReporting($this->topmost($scope, $tree), $weekStart, $sent);
        $chains = $roots->map(fn (Unit $root) => $this->chain->build($root, $weekStart));
        // Each root's per-trooper rows, read once: the tiles, the at-risk list and the live rows
        // of the unit table are all sums or filters of them.
        $attendance = $roots->map(fn (Unit $root) => $this->kpis->trooperAttendance($root, $weekStart));
        $paths = $tree->mapWithKeys(fn (Unit $unit) => [$unit->id => $unit->path]);

        return Inertia::render('Home', [
            'week' => [
                // Saturday 00:00 UTC (AD-11). The page draws Sat → Fri plus the due Saturday.
                'start' => $weekStart->toIso8601String(),
                'reportDue' => WeekOf::reportDue($weekStart)->toIso8601String(),
            ],
            'kpis' => $roots->isEmpty() ? null : $this->kpiProps($attendance, $roots, $scope, $weekStart),
            'attention' => $roots->isEmpty() ? null : $this->attentionProps($attendance, $chains, $scope, $weekStart),
            'steps' => $this->stepProps($scope, $weekStart, $reports, $sent),
            'units' => $chains->flatMap(function (ChainNode $chain, int $i) use ($roots, $attendance, $paths, $weekStart) {
                $rows = $attendance[$i];
                $sectionOf = $this->hasLiveRollup($chain) ? $this->troopers->sectionsOfAssignedWithin($roots[$i]) : collect();

                return collect($this->subordinates($chain))
                    ->map(fn (ChainNode $node) => $this->unitRow($node, $weekStart, $rows, $sectionOf, $paths));
            })
                ->values()
                ->all(),
        ]);
    }

    /**
     * The highest units of the scope: those whose parent is outside it. Scope is subtree-closed,
     * so every other unit in it sits under one of these.
     *
     * @param  Collection<int, int>  $scope
     * @param  Collection<int, Unit>  $tree
     * @return Collection<int, Unit>
     */
    private function topmost(Collection $scope, Collection $tree): Collection
    {
        $ids = $scope->flip();

        // Line units only: a group has no report chain or KPIs of its own (2026-10-24).
        return $tree
            ->filter(fn (Unit $unit) => $unit->isLine() && $ids->has($unit->id) && ($unit->parent_id === null || ! $ids->has($unit->parent_id)))
            ->sortBy('id')
            ->values()
            ->toBase();
    }

    /**
     * The six KPIs now, live for the whole scope (AD-21), and the same six for each of the
     * trailing {@see SPARKLINE_WEEKS} whole weeks, summed from the scope's sections' **sent**
     * snapshots. A past week is a point only when every section in scope sent that week: a partial
     * sum would read as a headcount dip that never happened, so it stays a gap (AD-12). On LOA and
     * at-risk move with leave, so the leave data's source and last sync go with them (4.3, AD-20).
     *
     * The live figures also carry the `headcount` (#424): everyone serving under the scope's roots, HQ staff and groups
     * included. It is a count beside the strength the rates are over, which stays the sections', and the history never
     * has one (a past week's headcount lives only in the report it was frozen into).
     *
     * @param  Collection<int, Collection<int, TrooperAttendance>>  $attendance  each root's per-trooper rows
     * @param  Collection<int, Unit>  $roots
     * @param  Collection<int, int>  $scope
     * @return array<string, mixed>
     */
    private function kpiProps(Collection $attendance, Collection $roots, Collection $scope, CarbonImmutable $weekStart): array
    {
        $current = $attendance->reduce(
            fn (UnitKpis $sum, Collection $rows) => $sum->plus($this->kpis->sum($rows)),
            UnitKpis::none(),
        );

        return [
            // Roots are disjoint subtrees, so their headcounts add.
            'current' => [...self::kpiShape($current), 'headcount' => $roots->sum(fn (Unit $root) => $this->headcount->forUnit($root))],
            'history' => $this->history($this->units->sectionsAmong($scope), $weekStart),
            'roster' => $this->rosterProps(),
            'loaSync' => $this->loaProvenance->current()->toArray(),
        ];
    }

    /**
     * Each past week's point waits on the sections that took part in that week ({@see Headcount::reportingLineUnits()}):
     * someone was placed in them during it, or they sent its report. Not on the sections that have troopers now, so a
     * section stood up since does not blank the weeks before it, and one emptied since still adds its numbers to the
     * weeks it sent. The sections, their sent reports and their placements are read once for the four weeks.
     *
     * @param  Collection<int, Unit>  $sections  every section in scope
     * @return list<array{week: string, kpis: array<string, mixed>|null}>
     */
    private function history(Collection $sections, CarbonImmutable $weekStart): array
    {
        $first = $weekStart->subWeeks(self::SPARKLINE_WEEKS);
        $last = $weekStart->subWeek();
        $sent = $this->reports
            ->sentWithin($sections->modelKeys(), $first, $last)
            ->groupBy(fn (WeeklyReport $report) => $report->week_start->toDateString());
        $takingPart = $this->headcount->reportingLineUnitsByWeek(
            $sections,
            $first,
            $last,
            $sent->map(fn (Collection $rows) => $rows->pluck('unit_id')->map(fn ($id) => (int) $id)->all())->all(),
        );

        return collect(range(self::SPARKLINE_WEEKS, 1))->map(function (int $back) use ($sent, $takingPart, $weekStart) {
            $week = $weekStart->subWeeks($back)->toDateString();
            $rows = $sent->get($week, collect());
            $expected = $takingPart[$week] ?? collect();

            return [
                'week' => $week,
                'kpis' => $expected->isNotEmpty() && $rows->count() === $expected->count() ? $this->sum($rows) : null,
            ];
        })->all();
    }

    /**
     * The sum of sent snapshots, or null when any is unreadable: a history point that cannot be
     * trusted is a gap, and one bad row must not take the whole dashboard down.
     *
     * @param  Collection<int, WeeklyReport>  $rows
     * @return array<string, mixed>|null
     */
    private function sum(Collection $rows): ?array
    {
        try {
            return self::kpiShape($rows->reduce(
                fn (UnitKpis $sum, WeeklyReport $report) => $sum->plus(UnitKpis::fromArray($report->kpis)),
                UnitKpis::none(),
            ));
        } catch (InvalidArgumentException|TypeError|ErrorException $e) {
            report($e);

            return null;
        }
    }

    /**
     * Where strength comes from and how fresh it is (AD-20): the roster sync, judged by the one
     * `RosterProvenance` rule every roster page shares. A failed, partial or overdue sync is a
     * visible state, never a silently old number.
     *
     * @return array{source: string, syncedAt: string|null, state: string}
     */
    private function rosterProps(): array
    {
        $sync = $this->rosterSync->current();

        return [
            'source' => $sync->source,
            'syncedAt' => $sync->lastSyncedAt?->toIso8601String(),
            'state' => $sync->state(),
        ];
    }

    /**
     * What a leader can act on today (Story 5.10): reports holding up the chain and at-risk
     * troopers, each with the screen to act on it. Both come from the shared pieces: the at-risk
     * definition is `TrooperAttendance::atRisk()` (AD-21) and a holding report is the roll-up's own
     * `isHolding()` (AD-13), so the list never disagrees with the tiles or the navigator.
     *
     * Leave (Epic 4) is not listed here: an expiring LOA would join as another `kind`, and a
     * `needs_review` LOA must then never produce an at-risk item (AD-4).
     *
     * @param  Collection<int, Collection<int, TrooperAttendance>>  $attendance  each root's per-trooper rows
     * @param  Collection<int, ChainNode>  $chains
     * @param  Collection<int, int>  $scope
     * @return array{items: list<array<string, mixed>>, totals: array{holding: int, atRisk: int}}
     */
    private function attentionProps(Collection $attendance, Collection $chains, Collection $scope, CarbonImmutable $weekStart): array
    {
        $holding = $chains
            ->flatMap(fn (ChainNode $chain) => $this->holdingBlockers($chain))
            ->sortBy(fn (array $blocker) => [$blocker['child']->status === ChildReportStatus::Draft ? 0 : 1, $blocker['child']->unit->id])
            ->values();

        $atRisk = $attendance
            ->flatMap(fn (Collection $rows) => $rows
                ->filter(fn (TrooperAttendance $row) => $row->atRisk())
                ->map(fn (TrooperAttendance $row) => ['trooperId' => $row->trooperId, 'missed' => $row->missed, 'missedWeeks' => $row->missedWeeks]))
            ->values();

        $troopers = $this->troopers->byIds($atRisk->pluck('trooperId')->all());
        $sections = $this->units->sectionsAmong($scope)->keyBy('id');
        $atRisk = $atRisk
            ->filter(fn (array $row) => $troopers->has($row['trooperId']))
            ->sortBy(fn (array $row) => [-$row['missed'], mb_strtolower($troopers->get($row['trooperId'])->display_name)])
            ->values();

        $date = $weekStart->toDateString();

        return [
            'items' => [
                ...$holding->take(self::WORKLIST_LIMIT)->map(fn (array $blocker) => [
                    'kind' => 'holding',
                    'id' => "holding-{$blocker['child']->unit->id}",
                    'unit' => self::unitProps($blocker['child']->unit),
                    'status' => $blocker['child']->status->value,
                    'holds' => self::unitProps($blocker['holds']),
                    'href' => route('reports.show', ['unit' => $blocker['child']->unit->id, 'week' => $date]),
                ])->all(),
                ...$atRisk->take(self::WORKLIST_LIMIT)->map(function (array $row) use ($troopers, $sections) {
                    $trooper = $troopers->get($row['trooperId']);
                    $section = $sections->get($trooper->primary_section_id);

                    return [
                        'kind' => 'at_risk',
                        'id' => "at-risk-{$trooper->id}",
                        'trooper' => [
                            'id' => $trooper->id,
                            'displayName' => $trooper->display_name,
                            'rankShort' => $trooper->rank_short,
                            'username' => $trooper->username,
                        ],
                        'section' => $section ? self::unitProps($section) : null,
                        'missed' => $row['missed'],
                        // The weeks behind `missed`, oldest first, for the makeup PM's title.
                        'missedWeeks' => $row['missedWeeks'],
                        'windowWeeks' => KpiService::WINDOW_WEEKS,
                        'href' => route('roster.index', array_filter(['unit' => $trooper->primary_section_id, 'trooper' => $trooper->id])),
                    ];
                })->all(),
            ],
            'totals' => ['holding' => $holding->count(), 'atRisk' => $atRisk->count()],
        ];
    }

    /**
     * The reports actually holding the chain up: each unsent child of a holding unit, except one
     * that is itself held up by its own children, since the blame sits deeper (AD-13).
     *
     * @return list<array{child: ChildReport, holds: Unit}>
     */
    private function holdingBlockers(ChainNode $node): array
    {
        $blockers = [];

        if ($node->isHolding()) {
            $nodes = collect($node->children)->keyBy(fn (ChainNode $child) => $child->unit->id);

            foreach ($node->rollup->missingChildren() as $child) {
                if (! $nodes->get($child->unit->id)?->isHolding()) {
                    $blockers[] = ['child' => $child, 'holds' => $node->unit];
                }
            }
        }

        foreach ($node->children as $child) {
            array_push($blockers, ...$this->holdingBlockers($child));
        }

        return $blockers;
    }

    /**
     * The week's five steps for every section in scope (the step matrix): rollcall posted, signed,
     * practice held, attendance marked and report sent, each with the screen and step that does it. Counts
     * are over the troopers currently assigned to the section, and a trooper on leave for the week
     * counts as signed and marked, since there is nothing to sign or mark for them; a step nobody has started is simply
     * not done, never an invented deadline miss.
     *
     * The sections are those that take part in the week ({@see Headcount::reportingLineUnits()}): placed in during it
     * (for the running week, placed in now too) or sent its report, the same ones Needs attention and the unit table
     * hold up or count.
     *
     * @param  Collection<int, int>  $scope
     * @param  Collection<int, WeeklyReport>  $reports  the week's reports in scope, keyed by unit id
     * @param  list<int>  $sent  the units in scope that sent the week's report
     * @return list<array<string, mixed>>
     */
    private function stepProps(Collection $scope, CarbonImmutable $weekStart, Collection $reports, array $sent): array
    {
        $sections = $this->headcount->reportingLineUnits($this->units->sectionsAmong($scope), $weekStart, $sent);
        $ids = $sections->modelKeys();
        $practices = $this->practices->forSections($ids, $weekStart);
        // A trooper whose leave covers the week has no mark to make (3.4): the one LOA definition says who (AD-21).
        $covered = array_keys(array_filter(
            $this->loaCoverage->forTroopers($this->troopers->placedInAny($ids)->modelKeys(), $weekStart),
            fn (LoaCover $cover) => $cover->isCovered(),
        ));
        $progress = $this->attendance->weekProgress($ids, $weekStart, $covered);
        $date = $weekStart->toDateString();

        return $sections->map(function (Unit $section) use ($practices, $progress, $reports, $date) {
            $week = $progress->get($section->id);
            $report = $reports->get($section->id);

            return [
                ...self::unitProps($section),
                'platoon' => $section->parent ? ($section->parent->callsign ?? $section->parent->name) : null,
                'strength' => $week?->strength ?? 0,
                'postedAt' => $practices->get($section->id)?->rollcall_posted_at?->toIso8601String(),
                'signed' => $week?->signed ?? 0,
                'held' => $week?->held ?? false,
                'marked' => $week?->marked ?? 0,
                'report' => match ($report?->status) {
                    WeeklyReportStatus::Sent => 'sent',
                    WeeklyReportStatus::Draft => 'draft',
                    null => 'not_started',
                },
                'sentAt' => $report?->sent_at?->toIso8601String(),
                // Each step opens the screen and the rollcall step where it gets done.
                'links' => [
                    'posted' => self::rollcallStep($section, $date, 3),
                    'signed' => self::rollcallStep($section, $date, 1),
                    'held' => self::rollcallStep($section, $date, 2),
                    'marked' => self::rollcallStep($section, $date, 2),
                    'report' => route('reports.show', ['unit' => $section->id, 'week' => $date]),
                ],
            ];
        })->values()->all();
    }

    /**
     * The units the table compares: each direct subordinate of a root of the scope, or the root
     * itself when it has none (a section leader's own section), so a leaf-only viewer sees their
     * section rather than a broken table.
     *
     * @return list<ChainNode>
     */
    private function subordinates(ChainNode $root): array
    {
        return $root->children === [] ? [$root] : $root->children;
    }

    /** Whether a table row under this root is an unsent non-leaf, the one kind summed live. */
    private function hasLiveRollup(ChainNode $root): bool
    {
        return collect($this->subordinates($root))->contains(fn (ChainNode $node) => $node->live && $node->children !== []);
    }

    /**
     * One table row. A sent report is its frozen snapshot, a leaf is live attendance, and an unsent
     * non-leaf is live for its whole subtree, never the partial sum of the children that happen to
     * have sent: the roll-up semantics (status, holding) stay the chain's own, so the table and the
     * navigator never disagree (AD-13). A sent report whose snapshot cannot be read has `kpis`
     * null: the row says so, and the rest of the table still renders. The live subtree is summed
     * from the root's per-trooper rows, as the roster totals a unit, rather than read again.
     *
     * @param  Collection<int, TrooperAttendance>  $rows  the root's per-trooper rows
     * @param  Collection<int, int>  $sectionOf  trooper id to section id, for the root
     * @param  Collection<int, string>  $paths  unit id to materialized path
     * @return array<string, mixed>
     */
    private function unitRow(ChainNode $node, CarbonImmutable $weekStart, Collection $rows, Collection $sectionOf, Collection $paths): array
    {
        $unit = $node->unit;
        $kpis = $node->live && $node->children !== []
            ? $this->kpis->sum($rows->filter(function (TrooperAttendance $row) use ($sectionOf, $paths, $unit) {
                $section = $sectionOf->get($row->trooperId);

                return $section !== null && str_starts_with($paths->get($section, ''), $unit->path);
            }))
            : $node->kpis;
        $date = $weekStart->toDateString();

        return [
            ...self::unitProps($unit),
            'type' => $unit->type->value,
            'status' => $node->status->value,
            'holding' => $node->isHolding(),
            'live' => $node->live,
            'kpis' => $kpis === null ? null : self::kpiShape($kpis),
            'coverage' => $node->rollup === null ? null : [
                'sent' => count($node->rollup->children) - count($node->rollup->missingChildren()),
                'of' => count($node->rollup->children),
            ],
            'href' => route('reports.show', ['unit' => $unit->id, 'week' => $date]),
        ];
    }

    /** A section's rollcall for the week, opened on a step: 1 signatures, 2 section practice, 3 review and post. */
    private static function rollcallStep(Unit $section, string $date, int $step): string
    {
        return route('rollcall.show', ['unit' => $section->id, 'week' => $date, 'step' => $step]);
    }

    /** @return array{id: int, designation: string, name: string} */
    private static function unitProps(Unit $unit): array
    {
        return ['id' => $unit->id, 'designation' => $unit->callsign ?? $unit->name, 'name' => $unit->name];
    }

    /** @return array<string, mixed> */
    private static function kpiShape(UnitKpis $kpis): array
    {
        return [
            'strength' => $kpis->strength,
            'rc' => ['numerator' => $kpis->rc30d->numerator, 'denominator' => $kpis->rc30d->denominator],
            'sp' => ['numerator' => $kpis->sp30d->numerator, 'denominator' => $kpis->sp30d->denominator],
            'atRisk' => $kpis->atRisk,
            'onLoa' => $kpis->onLoa,
            'makeups' => $kpis->makeups,
        ];
    }
}
