<?php

use App\Kpi\Ratio;
use App\Kpi\UnitKpis;

// 5.1 / 5.2: a frozen report stores its KPIs as a plain array; reading it back must give the
// identical DTO, so a snapshot and a live recompute compare equal.

it('round-trips through its storage array', function () {
    $kpis = new UnitKpis(
        strength: 12,
        rc30d: new Ratio(30, 44),
        sp30d: new Ratio(0, 0),
        atRisk: 2,
        onLoa: 1,
        makeups: 3,
    );

    expect($kpis->toArray())->toBe([
        'strength' => 12,
        'rc_30d' => ['numerator' => 30, 'denominator' => 44],
        'sp_30d' => ['numerator' => 0, 'denominator' => 0],
        'at_risk' => 2,
        'on_loa' => 1,
        'makeups' => 3,
    ])->and(UnitKpis::fromArray($kpis->toArray()))->toEqual($kpis);
});

it('refuses a count no computation could produce — negative, or more troopers than strength', function (array $counts) {
    new UnitKpis(...[
        'strength' => 2, 'rc30d' => new Ratio(0, 0), 'sp30d' => new Ratio(0, 0),
        'atRisk' => 0, 'onLoa' => 0, 'makeups' => 0, ...$counts,
    ]);
})->with([
    'negative at-risk' => [['atRisk' => -1]],
    'on-LOA over strength' => [['onLoa' => 3]],
    'makeups over strength' => [['makeups' => 3]],
])->throws(InvalidArgumentException::class);

// 5.7 / AD-21: a roll-up adds its children's KPIs — counts summed, ratios composed from their
// parts, never their percentages averaged.
it('adds another unit\'s KPIs — counts summed, ratios composed from numerators and denominators', function () {
    $a = new UnitKpis(strength: 4, rc30d: new Ratio(4, 4), sp30d: new Ratio(0, 0), atRisk: 1, onLoa: 0, makeups: 2);
    $b = new UnitKpis(strength: 6, rc30d: new Ratio(1, 3), sp30d: new Ratio(5, 8), atRisk: 2, onLoa: 1, makeups: 0);

    $sum = $a->plus($b);

    expect($sum)->toEqual(new UnitKpis(strength: 10, rc30d: new Ratio(5, 7), sp30d: new Ratio(5, 8), atRisk: 3, onLoa: 1, makeups: 2))
        ->and($sum->rc30d->value())->toBe(5 / 7); // not (100% + 33%) / 2
});

it('is the identity when added to the empty unit', function () {
    $kpis = new UnitKpis(strength: 3, rc30d: new Ratio(2, 3), sp30d: new Ratio(1, 2), atRisk: 1, onLoa: 1, makeups: 1);

    expect(UnitKpis::none()->plus($kpis))->toEqual($kpis)
        ->and(UnitKpis::none()->rc30d->value())->toBeNull();
});
