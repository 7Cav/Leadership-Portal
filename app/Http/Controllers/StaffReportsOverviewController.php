<?php

namespace App\Http\Controllers;

use App\Authorization\StaffReportAccess;
use App\Commands\StaffReports\PushStaffReportToSheetHandler;
use App\Kpi\StaffHqHealth;
use App\Milpac\StaffReportWeek;
use App\Models\StaffReport;
use App\Models\Trooper;
use App\Models\Unit;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The staff reports overview: for one Cav week, every platoon, company and battalion HQ staff member whose staff report
 * the viewer reads, grouped by HQ down the tree, with whether each sent theirs and attended a section practice, and
 * what else they reported. The viewer's own, when they file one, sits on top. Read only: each row links to the staff
 * member's report page.
 *
 * Who opens it: whoever reads some HQ's staff ({@see StaffReportAccess::readableHqs()}: command above a platoon,
 * company or battalion HQ, a staff grant or read scope there, Admins; a battalion HQ is read from the regiment). Anyone
 * else answers 404, like a page that does not exist (AD-8): a PL or PSG reads only their own, from "Staff report". A
 * view-as preview shows what its target would see.
 */
class StaffReportsOverviewController extends Controller
{
    public function __construct(
        private readonly StaffReportAccess $access,
        private readonly StaffReportWeek $week,
        private readonly PushStaffReportToSheetHandler $sheet,
        private readonly StaffHqHealth $health,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $hqs = $this->access->readableHqs($user);
        abort_if($hqs->isEmpty(), 404);

        $now = CarbonImmutable::now();
        $sheetConfigured = $this->sheet->sheetConfigured();
        $weekStart = $this->weekStart($request->query('week'), $now);
        $ended = WeekOf::hasEnded($weekStart, $now);
        $current = WeekOf::start($now);
        $own = $this->access->ownTrooper($user);
        $staff = $this->week->staffOfHqs($hqs->pluck('id')->map(fn ($id) => (int) $id)->all(), $weekStart);
        // The game server's view of every listed report at once (two queries), never of the viewer's own.
        $discrepancies = $this->week->discrepancies(
            collect($staff)->flatten(1)->pluck('report')->filter()
                ->reject(fn (StaffReport $report) => $own !== null && (int) $report->trooper_id === $own->id)
                ->unique('id')->values(),
            $weekStart,
        );

        $groups = $hqs
            ->map(fn (Unit $hq) => [
                'id' => $hq->id,
                'label' => $hq->callsign ?? $hq->name,
                'rows' => collect($staff[$hq->id])
                    ->reject(fn (array $row) => $own !== null && $row['trooper']->id === $own->id)
                    ->map(fn (array $row) => [
                        ...$this->row($row['trooper'], $row['report'], $hq, $weekStart, $ended),
                        'discrepancy' => $discrepancies[$row['trooper']->id] ?? null,
                    ])
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $group) => $group['rows'] !== [])
            ->values()
            ->all();

        return Inertia::render('StaffReports', [
            'week' => [
                'date' => $weekStart->toDateString(),
                'start' => $weekStart->toIso8601String(),
                'due' => WeekOf::reportDue($weekStart)->toIso8601String(),
                'previous' => $weekStart->subWeek()->toDateString(),
                'next' => $weekStart->addWeek()->lessThanOrEqualTo($current) ? $weekStart->addWeek()->toDateString() : null,
                'ended' => $ended,
            ],
            'own' => $this->ownRow($own, $weekStart, $ended),
            'sheetConfigured' => $sheetConfigured,
            'groups' => $groups,
            // One HQ's unit health at a time, so the page reads a bounded number of queries however many HQs it lists:
            // `?hq=` picks it among the listed HQs, else the first listed.
            'health' => $this->healthFor($request->query('hq'), $groups, $hqs, $staff, $weekStart),
        ]);
    }

    /**
     * {@see StaffHqHealth} for the picked HQ, with how many of its staff kept to the one practice rule, or null when no HQ
     * is listed.
     *
     * @param  list<array{id: int}>  $groups
     * @param  Collection<int, Unit>  $hqs
     * @param  array<int, list<array{trooper: Trooper, report: ?StaffReport}>>  $staff
     * @return array<string, mixed>|null
     */
    private function healthFor(mixed $picked, array $groups, Collection $hqs, array $staff, CarbonImmutable $weekStart): ?array
    {
        $listed = array_column($groups, 'id');
        $id = is_string($picked) && ctype_digit($picked) && in_array((int) $picked, $listed, true) ? (int) $picked : ($listed[0] ?? null);
        $hq = $id === null ? null : $hqs->firstWhere('id', $id);
        if ($hq === null) {
            return null;
        }

        $rows = $staff[$hq->id] ?? [];

        return [
            ...$this->health->forUnit($hq, $weekStart),
            'staff' => [
                'total' => count($rows),
                'met' => count(array_filter($rows, fn (array $row) => $row['report'] !== null
                    && StaffReportWeek::metRule(StaffReportWeek::reportProps($row['report'])))),
            ],
            'hqs' => array_map(fn (array $group) => ['id' => $group['id'], 'label' => $group['label']], $groups),
        ];
    }

    /**
     * The viewer's own staff report for the week, when they file one for it: null otherwise, and in a view-as preview
     * (whose viewer is not the staff member).
     *
     * @return array<string, mixed>|null
     */
    private function ownRow(?Trooper $own, CarbonImmutable $weekStart, bool $ended): ?array
    {
        $hq = $own === null ? null : $this->access->owes($own, $weekStart);
        if ($hq === null) {
            return null;
        }

        return $this->row($own, $this->week->find($own, $weekStart), $hq, $weekStart, $ended);
    }

    /**
     * One staff member's line: `status` is `sent`, `notSent` or `notDue` (the running week, sent once it ends).
     * `metRule` says whether they kept to at least one section practice (or an FTX) that week, null while not sent.
     * `practiceType`, `ftx`, `officeHour`, `otherEvents`, `notes` and `sheetPushedAt` are as sent ({@see StaffReportWeek::reportProps()}).
     *
     * @return array<string, mixed>
     */
    private function row(Trooper $trooper, ?StaffReport $report, Unit $hq, CarbonImmutable $weekStart, bool $ended): array
    {
        $props = $report === null ? null : StaffReportWeek::reportProps($report);
        $practices = $props === null ? [] : array_map(
            fn (array $practice) => $practice['section'] ?? 'A section no longer in the portal',
            $props['practices'],
        );

        return [
            'trooperId' => $trooper->id,
            'name' => $trooper->display_name,
            'billet' => $trooper->billet,
            'hq' => $hq->callsign ?? $hq->name,
            'status' => match (true) {
                $report !== null => 'sent',
                $ended => 'notSent',
                default => 'notDue',
            },
            'attended' => $report?->attended,
            'practices' => $practices,
            'practiceType' => $props['practiceType'] ?? null,
            'ftx' => $props['ftx'] ?? null,
            'officeHour' => $props['officeHour'] ?? null,
            'otherEvents' => $props['otherEvents'] ?? null,
            'notes' => $props['notes'] ?? null,
            'sheetPushedAt' => $props['sheetPushedAt'] ?? null,
            'metRule' => $props === null ? null : StaffReportWeek::metRule($props),
            'href' => route('staff-report.show', ['trooper' => $trooper->id, 'week' => $weekStart->toDateString()]),
        ];
    }

    /** `?week=YYYY-MM-DD`, a Saturday that opens a Cav week no later than the current one (AD-11), else 404; the last ended week when absent. */
    private function weekStart(mixed $week, CarbonImmutable $now): CarbonImmutable
    {
        if ($week === null || $week === '') {
            return WeekOf::lastEnded($now);
        }

        $date = is_string($week) ? CarbonImmutable::createFromFormat('!Y-m-d', $week, 'UTC') : false;

        abort_if($date === false || $date->toDateString() !== $week || ! WeekOf::start($date)->equalTo($date)
            || $date->greaterThan(WeekOf::start($now)), 404);

        return $date;
    }
}
