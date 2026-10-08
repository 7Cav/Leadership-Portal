<?php

namespace App\Commands\Demo;

use App\Authorization\ScopeResolver;
use App\Commands\WeeklyReports\SendWeeklyReportHandler;
use App\Kpi\KpiDelta;
use App\Kpi\KpiService;
use App\Kpi\ReportSnapshot;
use App\Kpi\RollupKpis;
use App\Kpi\UnitKpis;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use App\Repositories\AuditedBatch;
use App\Repositories\DemoHistoryRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Generates demo history (#115) for a unit's whole subtree from the **real** synced roster and
 * imported sections — it never creates, renames or alters troopers, units or users.
 *
 * For each of the last N completed Cav weeks (`WeekOf`, AD-11), oldest first, as **one audited
 * transaction per week** (AD-16, action `demo_history.generated`, target the chosen unit):
 *
 * 1. the subtree's existing demo rows for the week are cleared, so a rerun replaces rather than
 *    duplicates (and, the pattern being deterministic, reproduces the same marks);
 * 2. each section with placed troopers gets a demo practice and one attendance row per trooper —
 *    a rollcall signature and an SP mark from {@see DemoAttendancePattern};
 * 3. every section's report is frozen as sent, its KPIs from the shared service (AD-21) with the
 *    delta against the prior week's sent snapshot — the 5.1/5.6 snapshot shape;
 * 4. for every week but the most recent, every non-leaf unit with children is then sent from the
 *    roll-up of its children's sent snapshots (5.7), bottom-up, so deltas and the command roll-up
 *    have history while the latest week's platoon/company sends stay open for a live demo.
 *
 * A week that already holds real (non-demo) history anywhere in the subtree is skipped whole and
 * reported. The current week is never touched.
 *
 * Writes go through {@see DemoHistoryRepository}, the explicit tagged demo writer, rather than
 * `MarkAttendance`/`SendWeeklyReport`: those write one section, row by row, as a leader within
 * their scope, and `SendWeeklyReport` cannot send a non-leaf unit yet. The KPI numbers still come
 * only from `KpiService` and `RollupKpis`, and the digest from `SendWeeklyReportHandler`.
 */
class GenerateDemoHistoryHandler
{
    public const ACTION = 'demo_history.generated';

    public const NOTES = 'Demo data: generated for a demonstration, not a real report.';

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly DemoHistoryRepository $demo,
        private readonly WeeklyReportRepository $reports,
        private readonly KpiService $kpis,
        private readonly RollupKpis $rollups,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws DemoHistoryForbiddenException when the actor is not an Admin, or the unit is outside
     *                                       their scope or does not exist.
     */
    public function handle(User $actor, GenerateDemoHistory $command): DemoHistoryGenerated
    {
        $root = $this->authorizedUnit($actor, $command->unitId);
        $subtree = $this->demo->subtreeUnits($root);
        $sections = $subtree->filter(fn (Unit $unit) => $unit->type === UnitType::Section)->values();
        // Only troopers placed in an actual section: a practice belongs to a section (AD-14), and
        // clear/purge only ever look at sections.
        $placed = $this->demo->placedTroopers($root)->only($sections->modelKeys());

        if ($placed->isEmpty()) {
            return new DemoHistoryGenerated($root, [], [], 0, 0, 0, 0);
        }

        $current = WeekOf::start($command->now ?? CarbonImmutable::now('UTC'));
        $weeks = array_map(fn (int $back) => $current->subWeeks($back), range($command->weeks, 1));
        $sectionIds = $sections->modelKeys();
        $unitIds = $subtree->modelKeys();
        $skipped = $this->demo->weeksWithRealHistory($sectionIds, $unitIds, $weeks);
        $skippedAt = array_map(fn (CarbonImmutable $week) => $week->getTimestamp(), $skipped);
        $rollupUnits = $this->rollupUnitsBottomUp($subtree);
        $latest = end($weeks);

        $generated = [];
        $attendance = 0;
        $reports = 0;

        foreach ($weeks as $week) {
            if (in_array($week->getTimestamp(), $skippedAt, true)) {
                continue;
            }

            // Assigned, not accumulated, inside the mutation: the envelope may rerun it whole after a
            // deadlock, and a rerun must not count its rows twice.
            $weekAttendance = 0;
            $weekReports = 0;

            try {
                $this->audit->recordBatch(
                    actorId: $actor->id,
                    action: self::ACTION,
                    reason: 'Demo history for '.($root->callsign ?? $root->name).", week of {$week->toDateString()}",
                    reasonRequired: false,
                    mutation: function () use ($actor, $root, $week, $latest, $sections, $sectionIds, $unitIds, $placed, $rollupUnits, &$weekAttendance, &$weekReports) {
                        // Checked again inside the week's transaction: a leader may have recorded
                        // something real since the up-front check. Thrown, so nothing is written or audited.
                        if ($this->demo->weeksWithRealHistory($sectionIds, $unitIds, [$week]) !== []) {
                            throw new WeekHoldsRealHistory;
                        }

                        $this->demo->clearWeek($sectionIds, $unitIds, $week);

                        $practices = $this->demo->createPractices($placed->keys()->all(), $week);
                        $rows = [];

                        foreach ($practices as $sectionId => $practice) {
                            foreach (DemoAttendancePattern::forSectionWeek($sectionId, $week, $placed->get($sectionId)) as $mark) {
                                $rows[] = [...$mark, 'section_practice_id' => $practice->id];
                            }
                        }

                        $weekAttendance = $this->demo->insertAttendance($rows);

                        $prior = $this->reports->forUnits($unitIds, $week->subWeek());
                        $sent = $sections->map(fn (Unit $section) => $this->send($actor, $section, $week, $this->kpis->forUnit($section, $week), $prior));

                        if (! $week->equalTo($latest)) {
                            $sent = $sent->concat($rollupUnits->map(
                                fn (Unit $unit) => $this->send($actor, $unit, $week, $this->rollups->forUnit($unit, $week)->kpis, $prior),
                            ));
                        }

                        $weekReports = $sent->count();

                        return new AuditedBatch($root, ...$practices->values()->all(), ...$sent->all());
                    },
                );
            } catch (WeekHoldsRealHistory) {
                $skipped[] = $week;

                continue;
            }

            $attendance += $weekAttendance;
            $reports += $weekReports;
            $generated[] = $week;
        }

        return new DemoHistoryGenerated(
            unit: $root,
            generatedWeeks: $generated,
            skippedWeeks: collect($skipped)->sortBy(fn (CarbonImmutable $week) => $week->getTimestamp())->values()->all(),
            troopers: $placed->sum(fn (array $ids) => count($ids)),
            sections: $placed->count(),
            attendance: $attendance,
            reports: $reports,
        );
    }

    private function authorizedUnit(User $actor, int $unitId): Unit
    {
        if (! $actor->is_admin) {
            throw DemoHistoryForbiddenException::notAdmin();
        }

        $unit = $this->scope->resolve($actor)->contains($unitId) ? $this->units->find($unitId) : null;

        return $unit ?? throw DemoHistoryForbiddenException::outOfScope($unitId);
    }

    /**
     * The subtree's non-leaf units that have children to roll up, deepest echelon first, so each
     * one is sent after every child it sums.
     *
     * @param  Collection<int, Unit>  $subtree
     * @return Collection<int, Unit>
     */
    private function rollupUnitsBottomUp(Collection $subtree): Collection
    {
        $parents = $subtree->pluck('parent_id')->filter()->unique()->all();

        return $subtree
            ->filter(fn (Unit $unit) => $unit->type !== UnitType::Section && in_array($unit->id, $parents, true))
            ->sortByDesc(fn (Unit $unit) => [$unit->type->rank(), -$unit->id])
            ->values();
    }

    /**
     * Freeze `$kpis` as the unit-week's sent demo report, with its delta against the prior week's
     * sent snapshot (none when the prior week was not sent — a first report).
     *
     * @param  Collection<int, WeeklyReport>  $prior  the prior week's reports, keyed by unit id
     */
    private function send(User $actor, Unit $unit, CarbonImmutable $week, UnitKpis $kpis, Collection $prior): WeeklyReport
    {
        $priorReport = $prior->get($unit->id);
        $delta = $priorReport?->status === WeeklyReportStatus::Sent
            ? KpiDelta::between($kpis, ReportSnapshot::fromArray($priorReport->kpis)->kpis)
            : null;
        $snapshot = (new ReportSnapshot($kpis, $delta))->toArray();

        return $this->demo->createSentReport(
            $unit->id,
            $week,
            $snapshot,
            self::NOTES,
            $actor->id,
            SendWeeklyReportHandler::contentDigest($unit->id, $week, $snapshot, self::NOTES, null),
        );
    }
}
