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
