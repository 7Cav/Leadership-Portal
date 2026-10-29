<?php

namespace App\Http\Controllers;

use App\Authorization\StaffReportAccess;
use App\Milpac\StaffReportWeek;
use App\Models\StaffReport;
use App\Models\Trooper;
use App\Models\Unit;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The staff reports overview: for one Cav week, every platoon and company HQ staff member whose staff report the viewer
 * reads, grouped by HQ down the tree, with whether each sent theirs and attended a section practice. The viewer's own,
 * when they file one, sits on top. Read only: each row links to the staff member's report page.
 *
 * Who opens it: whoever reads some HQ's staff ({@see StaffReportAccess::readableHqs()}: command above a platoon or
 * company HQ, a staff grant or read scope there, Admins). Anyone else answers 404, like a page that does not exist
 * (AD-8): a PL or PSG reads only their own, from "Staff report". A view-as preview shows what its target would see.
 */
class StaffReportsOverviewController extends Controller
{
    public function __construct(
        private readonly StaffReportAccess $access,
        private readonly StaffReportWeek $week,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $hqs = $this->access->readableHqs($user);
        abort_if($hqs->isEmpty(), 404);

        $now = CarbonImmutable::now();
        $weekStart = $this->weekStart($request->query('week'), $now);
        $ended = WeekOf::hasEnded($weekStart, $now);
        $current = WeekOf::start($now);
        $own = $this->access->ownTrooper($user);
        $staff = $this->week->staffOfHqs($hqs->pluck('id')->map(fn ($id) => (int) $id)->all(), $weekStart);

        $groups = $hqs
            ->map(fn (Unit $hq) => [
                'id' => $hq->id,
                'label' => $hq->callsign ?? $hq->name,
                'rows' => collect($staff[$hq->id])
                    ->reject(fn (array $row) => $own !== null && $row['trooper']->id === $own->id)
                    ->map(fn (array $row) => $this->row($row['trooper'], $row['report'], $hq, $weekStart, $ended))
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
            'groups' => $groups,
        ]);
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

        $report = $this->week->report($own, $weekStart);

        return [
            ...$this->row($own, null, $hq, $weekStart, $ended),
            ...($report === null ? [] : [
                'status' => 'sent',
                'attended' => $report['attended'],
                'practices' => array_map(fn (array $practice) => $practice['section'] ?? 'A section no longer in the portal', $report['practices']),
                'metRule' => $report['attended'] && $report['practices'] !== [],
            ]),
        ];
    }

    /**
     * One staff member's line: `status` is `sent`, `notSent` or `notDue` (the running week, sent once it ends).
     * `metRule` says whether they kept to at least one section practice that week, null while not sent.
     *
     * @return array{trooperId: int, name: string, billet: ?string, hq: string, status: string, attended: ?bool, practices: list<string>, metRule: ?bool, href: string}
     */
    private function row(Trooper $trooper, ?StaffReport $report, Unit $hq, CarbonImmutable $weekStart, bool $ended): array
    {
        $practices = $report === null ? [] : array_map(
            fn (array $practice) => $practice['section'] ?? 'A section no longer in the portal',
            StaffReportWeek::reportProps($report)['practices'],
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
            'metRule' => $report === null ? null : $report->attended && $practices !== [],
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
