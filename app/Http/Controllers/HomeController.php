<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Kpi\KpiService;
use App\Kpi\UnitKpis;
use App\Models\SyncRunStatus;
use App\Models\Unit;
use App\Models\WeeklyReport;
use App\Repositories\SyncRunRepository;
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

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly WeeklyReportRepository $reports,
        private readonly SyncRunRepository $syncRuns,
        private readonly KpiService $kpis,
    ) {}

    public function show(Request $request): Response
    {
        // The week whose report cycle is running: the closing week stays current until its report
        // deadline passes, so the deadline counted down is never one that already went by.
        $weekStart = WeekOf::reporting(CarbonImmutable::now());
        $scope = $this->scope->resolve($request->user());
        $roots = $this->topmost($scope);

        return Inertia::render('Home', [
            'week' => [
                // Saturday 00:00 UTC (AD-11). The page draws Sat → Fri plus the due Saturday.
                'start' => $weekStart->toIso8601String(),
                'reportDue' => WeekOf::reportDue($weekStart)->toIso8601String(),
            ],
            'kpis' => $roots->isEmpty() ? null : $this->kpiProps($roots, $scope, $weekStart),
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
