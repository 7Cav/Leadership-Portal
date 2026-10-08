<?php

use App\Kpi\Ratio;

// 5.1 / AD-21: a KPI percentage travels as its numerator and denominator so a roll-up (5.7) can
// sum children before dividing; zero eligible is "not applicable", never a misleading 0%.

it('divides numerator by denominator', function () {
    expect((new Ratio(3, 4))->value())->toBe(0.75)
        ->and((new Ratio(0, 4))->value())->toBe(0.0);
});

it('adds parts rather than averaging percentages', function () {
    expect((new Ratio(1, 1))->plus(new Ratio(0, 3)))->toEqual(new Ratio(1, 4));
});

it('is not applicable when nothing was eligible', function () {
    expect((new Ratio(0, 0))->value())->toBeNull();
});

it('refuses a numerator larger than its denominator, or a negative count', function (int $numerator, int $denominator) {
    new Ratio($numerator, $denominator);
})->with([
    'over 100%' => [5, 4],
    'negative numerator' => [-1, 4],
    'negative denominator' => [0, -1],
])->throws(InvalidArgumentException::class);
