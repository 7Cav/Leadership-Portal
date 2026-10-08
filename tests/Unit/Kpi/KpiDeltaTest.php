<?php

use App\Kpi\KpiDelta;
use App\Kpi\Ratio;
use App\Kpi\UnitKpis;

// 5.4 / 5.6: a report's delta is the per-KPI change against the same unit's prior-week sent
// snapshot — counts as signed differences, rates as the change in their fraction.

function deltaKpis(int $strength, Ratio $rc, Ratio $sp, int $atRisk = 0, int $onLoa = 0, int $makeups = 0): UnitKpis
{
    return new UnitKpis($strength, $rc, $sp, $atRisk, $onLoa, $makeups);
}

it('subtracts the prior week from this week, KPI by KPI', function () {
    $prior = deltaKpis(10, new Ratio(20, 40), new Ratio(9, 12), atRisk: 3, onLoa: 2, makeups: 0);
    $current = deltaKpis(12, new Ratio(36, 48), new Ratio(6, 12), atRisk: 1, onLoa: 2, makeups: 4);

    expect(KpiDelta::between($current, $prior))->toEqual(new KpiDelta(
        strength: 2,
        rc30d: 0.25,
        sp30d: -0.25,
        atRisk: -2,
        onLoa: 0,
        makeups: 4,
    ));
});

it('leaves a rate\'s delta null when either week had nothing eligible, never a fake 0', function (Ratio $current, Ratio $prior) {
    $delta = KpiDelta::between(deltaKpis(4, $current, $current), deltaKpis(4, $prior, $prior));

    expect($delta->rc30d)->toBeNull()->and($delta->sp30d)->toBeNull();
})->with([
    'nothing eligible this week' => [new Ratio(0, 0), new Ratio(1, 2)],
    'nothing eligible last week' => [new Ratio(1, 2), new Ratio(0, 0)],
]);

it('round-trips through its storage array', function () {
    $delta = new KpiDelta(strength: -1, rc30d: 0.0, sp30d: null, atRisk: 1, onLoa: -2, makeups: 0);

    expect($delta->toArray())->toBe([
        'strength' => -1,
        'rc_30d' => 0.0,
        'sp_30d' => null,
        'at_risk' => 1,
        'on_loa' => -2,
        'makeups' => 0,
    ])->and(KpiDelta::fromArray($delta->toArray()))->toEqual($delta);
});
