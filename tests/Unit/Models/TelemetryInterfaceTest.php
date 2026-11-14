<?php

use App\Models\TelemetryInterface;

it('reads attendance only through a real interface: none and impossible read nothing', function () {
    expect(array_map(fn (TelemetryInterface $interface) => [$interface->value, $interface->reads()], TelemetryInterface::cases()))->toBe([
        ['crcon', true],
        ['battlemetrics', true],
        ['nightwatch', true],
        ['none', false],
        ['impossible', false],
    ]);
});

it('names impossible apart from none, which means not set up yet', function () {
    expect(TelemetryInterface::None->label())->toBe('None')
        ->and(TelemetryInterface::Impossible->label())->toBe('None (impossible)');
});
