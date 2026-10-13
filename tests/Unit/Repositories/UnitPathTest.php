<?php

use App\Repositories\UnitPath;

// The path order (AD-18), tested directly: `tree()`, `sectionsAmong()` and the demo subtree all sort
// with `compare()`.

it('orders siblings by id as numbers, not as text', function () {
    expect(UnitPath::compare('1/2/', '1/145/'))->toBeLessThan(0)
        ->and(UnitPath::compare('1/145/', '1/2/'))->toBeGreaterThan(0);
});

it('puts an ancestor before its descendant', function () {
    expect(UnitPath::compare('1/2/', '1/2/7/'))->toBeLessThan(0)
        ->and(UnitPath::compare('1/2/7/', '1/2/'))->toBeGreaterThan(0);
});

it('puts a descendant before its ancestor\'s next sibling, whatever the ids', function () {
    expect(UnitPath::compare('1/2/900/', '1/3/'))->toBeLessThan(0);
});

it('treats a path as equal to itself', function () {
    expect(UnitPath::compare('1/2/7/', '1/2/7/'))->toBe(0);
});

it('sorts a shuffled set depth-first', function () {
    $paths = ['1/145/', '1/2/10/', '1/', '1/2/', '1/10/', '1/2/9/', '1/145/3/'];

    usort($paths, UnitPath::compare(...));

    expect($paths)->toBe(['1/', '1/2/', '1/2/9/', '1/2/10/', '1/10/', '1/145/', '1/145/3/']);
});
