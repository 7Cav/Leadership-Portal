<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Kpi\ChainNode;
use App\Kpi\ChildReport;
use App\Kpi\ChildReportStatus;
use App\Kpi\KpiService;
use App\Kpi\ReportChain;
use App\Kpi\TrooperAttendance;
use App\Kpi\UnitKpis;
use App\Loa\LoaCover;
use App\Loa\LoaCoverage;
use App\Models\SyncRunStatus;
use App\Models\Unit;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\SyncRunRepository;
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

    /** Hours without a successful roster sync before the dashboard calls the roster stale. */
    private const ROSTER_STALE_AFTER_HOURS = 6;

    /** Most items one worklist group lists; the rest are counted, not drawn. */
    private const WORKLIST_LIMIT = 10;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly WeeklyReportRepository $reports,
        private readonly SyncRunRepository $syncRuns,
        private readonly TrooperRepository $troopers,
        private readonly SectionPracticeRepository $practices,
        private readonly AttendanceRepository $attendance,
        private readonly KpiService $kpis,
        private readonly ReportChain $chain,
        private readonly LoaCoverage $loaCoverage,
    ) {}

    public function show(Request $request): Response
    {
        // The week whose report cycle is running: the closing week stays current until its report
        // deadline passes, so the deadline counted down is never one that already went by.
        $weekStart = WeekOf::reporting(CarbonImmutable::now());
        $scope = $this->scope->resolve($request->user());
        $roots = $this->topmost($scope);
        $chains = $roots->map(fn (Unit $root) => $this->chain->build($root, $weekStart));

        return Inertia::render('Home', [
            'week' => [
                // Saturday 00:00 UTC (AD-11). The page draws Sat → Fri plus the due Saturday.
                'start' => $weekStart->toIso8601String(),
                'reportDue' => WeekOf::reportDue($weekStart)->toIso8601String(),
            ],
            'kpis' => $roots->isEmpty() ? null : $this->kpiProps($roots, $scope, $weekStart),
            'attention' => $roots->isEmpty() ? null : $this->attentionProps($roots, $chains, $scope, $weekStart),
            'steps' => $this->stepProps($scope, $weekStart),
            'units' => $chains->flatMap(fn (ChainNode $chain) => $this->subordinates($chain))
                ->map(fn (ChainNode $node) => $this->unitRow($node, $weekStart))
                ->values()
                ->all(),
        ]);
    }

    /**
     * The highest units of the scope: those whose parent is outside it. Scope is subtree-closed,
     * so every other unit in it sits under one of these.
     *
     * @param  Collection<int, int>  $scope
     * @return Collection<int, Unit>
     */
    private function topmost(Collection $scope): Collection
    {
        $ids = $scope->flip();

        return $this->units->tree()
            ->filter(fn (Unit $unit) => $ids->has($unit->id) && ($unit->parent_id === null || ! $ids->has($unit->parent_id)))
            ->sortBy('id')
            ->values()
            ->toBase();
    }

    /**
     * The six KPIs now, live for the whole scope (AD-21), and the same six for each of the
     * trailing {@see SPARKLINE_WEEKS} whole weeks, summed from the scope's sections' **sent**
     * snapshots. A past week is a point only when every section in scope sent that week: a partial
     * sum would read as a headcount dip that never happened, so it stays a gap (AD-12).
     *
     * @param  Collection<int, Unit>  $roots
     * @param  Collection<int, int>  $scope
     * @return array<string, mixed>
     */
    private function kpiProps(Collection $roots, Collection $scope, CarbonImmutable $weekStart): array
    {
        $current = $roots->reduce(
            fn (UnitKpis $sum, Unit $root) => $sum->plus($this->kpis->forUnit($root, $weekStart)),
            UnitKpis::none(),
        );

        return [
            'current' => self::kpiShape($current),
            'history' => $this->history($this->units->sectionsAmong($scope), $weekStart),
            'roster' => $this->rosterProps(),
        ];
    }

    /**
     * @param  Collection<int, Unit>  $sections
     * @return list<array{week: string, kpis: array<string, mixed>|null}>
     */
    private function history(Collection $sections, CarbonImmutable $weekStart): array
    {
        $sent = $this->reports
            ->sentWithin($sections->modelKeys(), $weekStart->subWeeks(self::SPARKLINE_WEEKS), $weekStart->subWeek())
            ->groupBy(fn (WeeklyReport $report) => $report->week_start->toDateString());

        return collect(range(self::SPARKLINE_WEEKS, 1))->map(function (int $back) use ($sections, $sent, $weekStart) {
            $week = $weekStart->subWeeks($back);
            $rows = $sent->get($week->toDateString(), collect());

            return [
                'week' => $week->toDateString(),
                'kpis' => $sections->isNotEmpty() && $rows->count() === $sections->count() ? $this->sum($rows) : null,
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
     * Where strength comes from and how fresh it is (AD-20): the milpac roster sync. A failed or
     * overdue sync is a visible state, never a silently old number.
     *
     * @return array{source: string, syncedAt: string|null, state: string}
     */
    private function rosterProps(): array
    {
        $success = $this->syncRuns->latestSuccessful('roster');
        $latest = $this->syncRuns->latestFinished('roster');

        $failed = $latest?->status === SyncRunStatus::Failure
            && ($success === null || $latest->finished_at->greaterThan($success->finished_at));

        $state = match (true) {
            $failed => 'failed',
            $success === null => 'never',
            $success->finished_at->lt(CarbonImmutable::now()->subHours(self::ROSTER_STALE_AFTER_HOURS)) => 'stale',
            default => 'ok',
        };

        return [
            'source' => 'milpac',
            'syncedAt' => $success?->finished_at?->toIso8601String(),
            'state' => $state,
        ];
    }

    /**
     * What a leader can act on today (Story 5.10): reports holding up the chain and at-risk
     * troopers, each with the screen to act on it. Both come from the shared pieces: the at-risk
     * definition is `TrooperAttendance::atRisk()` (AD-21) and a holding report is the roll-up's own
     * `isHolding()` (AD-13), so the list never disagrees with the tiles or the navigator.
     *
     * LOA is not modelled yet (Epic 4): an expiring LOA joins here as another `kind` once it is,
     * and a `needs_review` LOA must then never produce an at-risk item (AD-4).
     *
     * @param  Collection<int, Unit>  $roots
     * @param  Collection<int, ChainNode>  $chains
     * @param  Collection<int, int>  $scope
     * @return array{items: list<array<string, mixed>>, totals: array{holding: int, atRisk: int}}
     */
    private function attentionProps(Collection $roots, Collection $chains, Collection $scope, CarbonImmutable $weekStart): array
    {
        $holding = $chains
            ->flatMap(fn (ChainNode $chain) => $this->holdingBlockers($chain))
            ->sortBy(fn (array $blocker) => [$blocker['child']->status === ChildReportStatus::Draft ? 0 : 1, $blocker['child']->unit->id])
            ->values();

        $atRisk = $roots
            ->flatMap(fn (Unit $root) => $this->kpis->trooperAttendance($root, $weekStart)
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
     * practice held, attendance marked and report sent, each with the screen that does it. Counts
     * are over the troopers currently assigned to the section, and a trooper on leave for the week
     * counts as marked, since there is nothing to mark for them; a step nobody has started is simply
     * not done, never an invented deadline miss.
     *
     * @param  Collection<int, int>  $scope
     * @return list<array<string, mixed>>
     */
    private function stepProps(Collection $scope, CarbonImmutable $weekStart): array
    {
        $sections = $this->units->sectionsAmong($scope);
        $ids = $sections->modelKeys();
        $practices = $this->practices->forSections($ids, $weekStart);
        // A trooper whose leave covers the week has no mark to make (3.4): the one LOA definition says who (AD-21).
        $covered = array_keys(array_filter(
            $this->loaCoverage->forTroopers($this->troopers->placedInAny($ids)->modelKeys(), $weekStart),
            fn (LoaCover $cover) => $cover->isCovered(),
        ));
        $progress = $this->attendance->weekProgress($ids, $weekStart, $covered);
        $reports = $this->reports->forUnits($ids, $weekStart);
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
                'links' => [
                    'rollcall' => route('rollcall.show', ['unit' => $section->id, 'week' => $date]),
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

    /**
     * One table row. A sent report is its frozen snapshot, a leaf is live attendance, and an unsent
     * non-leaf is live for its whole subtree, never the partial sum of the children that happen to
     * have sent: the roll-up semantics (status, holding) stay the chain's own, so the table and the
     * navigator never disagree (AD-13). A sent report whose snapshot cannot be read has `kpis`
     * null: the row says so, and the rest of the table still renders.
     *
     * @return array<string, mixed>
     */
    private function unitRow(ChainNode $node, CarbonImmutable $weekStart): array
    {
        $unit = $node->unit;
        $kpis = $node->live && $node->children !== [] ? $this->kpis->forUnit($unit, $weekStart) : $node->kpis;
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
