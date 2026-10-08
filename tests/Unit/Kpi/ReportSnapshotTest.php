<?php

use App\Kpi\KpiDelta;
use App\Kpi\Ratio;
use App\Kpi\ReportSnapshot;
use App\Kpi\UnitKpis;

// 5.6: the frozen `weekly_reports.kpis` shape — the six KPIs in `UnitKpis::toArray()`'s shape, plus
// the delta under `delta` (null when the prior week has no sent report). Readers rebuild it with
// `fromArray()`.

it('stores the six KPIs with the delta alongside them', function () {
    $kpis = new UnitKpis(8, new Ratio(20, 32), new Ratio(5, 8), 1, 0, 2);
    $delta = new KpiDelta(1, 1250, null, 0, -1, 2);
    $snapshot = new ReportSnapshot($kpis, $delta);

    expect($snapshot->toArray())->toBe([...$kpis->toArray(), 'delta' => $delta->toArray(), 'missing_children' => []])
        ->and(ReportSnapshot::fromArray($snapshot->toArray()))->toEqual($snapshot);
});

it('stores a roll-up\'s missing children, and reads a snapshot without them as complete', function () {
    $kpis = new UnitKpis(8, new Ratio(20, 32), new Ratio(5, 8), 1, 0, 2);
    $snapshot = new ReportSnapshot($kpis, null, [12, 14]);

    expect(ReportSnapshot::fromArray($snapshot->toArray())->missingChildren)->toBe([12, 14])
        ->and(ReportSnapshot::fromArray([...$kpis->toArray(), 'delta' => null])->missingChildren)->toBe([]);
});

it('stores a null delta for a first report, and reads it back as null', function () {
    $snapshot = new ReportSnapshot(new UnitKpis(3, new Ratio(0, 12), new Ratio(0, 0), 0, 0, 0), null);

    expect($snapshot->toArray()['delta'])->toBeNull()
        ->and(ReportSnapshot::fromArray($snapshot->toArray()))->toEqual($snapshot);
});

it('fails loudly on a stored array that is not a report snapshot', function (array $stored) {
    ReportSnapshot::fromArray($stored);
})->with([
    'no delta key — must not pass as a first report' => [(new UnitKpis(3, new Ratio(0, 12), new Ratio(0, 0), 0, 0, 0))->toArray()],
    'a KPI missing' => [['strength' => 3, 'delta' => null]],
    'a delta key missing' => [[...(new UnitKpis(3, new Ratio(0, 12), new Ratio(0, 0), 0, 0, 0))->toArray(), 'delta' => ['strength' => 1]]],
    'missing_children not a list of ids' => [[...(new UnitKpis(3, new Ratio(0, 12), new Ratio(0, 0), 0, 0, 0))->toArray(), 'delta' => null, 'missing_children' => ['7']]],
    'missing_children a scalar' => [[...(new UnitKpis(3, new Ratio(0, 12), new Ratio(0, 0), 0, 0, 0))->toArray(), 'delta' => null, 'missing_children' => 7]],
    'a pre-basis-point float delta' => [[...(new UnitKpis(3, new Ratio(0, 12), new Ratio(0, 0), 0, 0, 0))->toArray(), 'delta' => ['strength' => 0, 'rc_30d' => 0.1, 'sp_30d' => null, 'at_risk' => 0, 'on_loa' => 0, 'makeups' => 0]]],
])->throws(InvalidArgumentException::class);
