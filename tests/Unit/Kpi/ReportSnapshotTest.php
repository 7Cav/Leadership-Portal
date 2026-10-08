<?php

use App\Kpi\KpiDelta;
use App\Kpi\Ratio;
use App\Kpi\ReportSnapshot;
use App\Kpi\UnitKpis;

// 5.6: the frozen `weekly_reports.kpis` shape — the six KPIs as 5.1 stores them, plus the delta
// under `delta` (null on a unit's first report). Readers rebuild it with `fromArray()`.

it('stores the six KPIs with the delta alongside them', function () {
    $kpis = new UnitKpis(8, new Ratio(20, 32), new Ratio(5, 8), 1, 0, 2);
    $delta = new KpiDelta(1, 0.125, null, 0, -1, 2);
    $snapshot = new ReportSnapshot($kpis, $delta);

    expect($snapshot->toArray())->toBe([...$kpis->toArray(), 'delta' => $delta->toArray()])
        ->and(ReportSnapshot::fromArray($snapshot->toArray()))->toEqual($snapshot);
});

it('stores a null delta for a first report, and reads it back as null', function () {
    $snapshot = new ReportSnapshot(new UnitKpis(3, new Ratio(0, 12), new Ratio(0, 0), 0, 0, 0), null);

    expect($snapshot->toArray()['delta'])->toBeNull()
        ->and(ReportSnapshot::fromArray($snapshot->toArray()))->toEqual($snapshot);
});

it('fails loudly on a stored snapshot with no delta key rather than guessing a first report', function () {
    ReportSnapshot::fromArray((new UnitKpis(3, new Ratio(0, 12), new Ratio(0, 0), 0, 0, 0))->toArray());
})->throws(InvalidArgumentException::class);
