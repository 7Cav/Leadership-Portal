<?php

use App\Kpi\ChildReport;
use App\Kpi\ChildReportStatus;
use App\Kpi\Ratio;
use App\Kpi\RollupResult;
use App\Kpi\UnitKpis;
use App\Models\Unit;

// 5.7 / AD-13: a roll-up's total is derived from its own rows, and a draft child's numbers can
// never ride along into it.

function someKpis(int $strength = 2): UnitKpis
{
    return new UnitKpis($strength, new Ratio(1, 2), new Ratio(1, 2), 0, 0, 0);
}

it('carries KPIs exactly when the child is sent', function (ChildReportStatus $status, ?UnitKpis $kpis) {
    new ChildReport(new Unit, $status, $kpis);
})->with([
    'a draft with KPIs' => [ChildReportStatus::Draft, someKpis()],
    'an unstarted child with KPIs' => [ChildReportStatus::NotStarted, someKpis()],
    'a sent child without KPIs' => [ChildReportStatus::Sent, null],
])->throws(InvalidArgumentException::class);

it('sums only the sent children into its KPIs and names the rest as missing', function () {
    $sent = new ChildReport(new Unit, ChildReportStatus::Sent, someKpis(2));
    $alsoSent = new ChildReport(new Unit, ChildReportStatus::Sent, someKpis(3));
    $draft = new ChildReport(new Unit, ChildReportStatus::Draft, null);

    $result = new RollupResult([$sent, $draft, $alsoSent]);

    expect($result->kpis)->toEqual(someKpis(2)->plus(someKpis(3)))
        ->and($result->missingChildren())->toBe([$draft])
        ->and($result->isComplete())->toBeFalse();
});

it('refuses a roll-up with no children, which would read as vacuously complete', function () {
    new RollupResult([]);
})->throws(InvalidArgumentException::class);
