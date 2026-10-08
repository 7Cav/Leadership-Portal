<?php

use App\Kpi\KpiDelta;
use App\Kpi\Ratio;
use App\Kpi\UnitKpis;

// 5.4 / 5.6: a report's delta is the per-KPI change against the same unit's prior-week sent
// snapshot — counts as signed differences, rates as the change in their fraction in basis points.

function deltaKpis(int $strength, Ratio $rc, Ratio $sp, int $atRisk = 0, int $onLoa = 0, int $makeups = 0): UnitKpis
{
    return new UnitKpis($strength, $rc, $sp, $atRisk, $onLoa, $makeups);
}

it('subtracts the prior week from this week, KPI by KPI', function () {
    $prior = deltaKpis(10, new Ratio(20, 40), new Ratio(9, 12), atRisk: 3, onLoa: 2, makeups: 0);
    $current = deltaKpis(12, new Ratio(36, 48), new Ratio(6, 12), atRisk: 1, onLoa: 2, makeups: 4);

    expect(KpiDelta::between($current, $prior))->toEqual(new KpiDelta(
        strength: 2,
        rc30dBp: 2500,
        sp30dBp: -2500,
        atRisk: -2,
        onLoa: 0,
        makeups: 4,
    ));
});

it('leaves a rate\'s delta null when either week had nothing eligible, never a fake 0', function (Ratio $current, Ratio $prior) {
    $delta = KpiDelta::between(deltaKpis(4, $current, $current), deltaKpis(4, $prior, $prior));

    expect($delta->rc30dBp)->toBeNull()->and($delta->sp30dBp)->toBeNull();
})->with([
    'nothing eligible this week' => [new Ratio(0, 0), new Ratio(1, 2)],
    'nothing eligible last week' => [new Ratio(1, 2), new Ratio(0, 0)],
]);

it('rounds a rate change to the nearest basis point, so the snapshot holds no float', function () {
    $delta = KpiDelta::between(
        deltaKpis(3, new Ratio(1, 3), new Ratio(2, 3)),
        deltaKpis(3, new Ratio(0, 3), new Ratio(1, 11)),
    );

    expect($delta->rc30dBp)->toBe(3333)  // +33.33 points
        ->and($delta->sp30dBp)->toBe(5758); // 2/3 − 1/11 = 0.57575…
});

it('refuses a rate delta no two fractions in [0, 1] could produce', function () {
    new KpiDelta(strength: 0, rc30dBp: 10_001, sp30dBp: null, atRisk: 0, onLoa: 0, makeups: 0);
})->throws(InvalidArgumentException::class);

it('round-trips through its storage array', function () {
    $delta = new KpiDelta(strength: -1, rc30dBp: 0, sp30dBp: null, atRisk: 1, onLoa: -2, makeups: 0);

    expect($delta->toArray())->toBe([
        'strength' => -1,
        'rc_30d_bp' => 0,
        'sp_30d_bp' => null,
        'at_risk' => 1,
        'on_loa' => -2,
        'makeups' => 0,
    ])->and(KpiDelta::fromArray($delta->toArray()))->toEqual($delta);
});
