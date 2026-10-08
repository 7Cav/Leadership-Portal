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
