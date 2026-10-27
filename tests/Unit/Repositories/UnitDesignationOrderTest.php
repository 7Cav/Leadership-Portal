<?php

use App\Models\Unit;
use App\Repositories\UnitDesignationOrder;

// The designation order, tested directly: the rollcall landing and the template screen's defaults sort with it.

function designationUnit(int $id, string $path, ?string $callsign, string $name = 'Unit'): Unit
{
    return (new Unit)->forceFill(['id' => $id, 'path' => $path, 'callsign' => $callsign, 'name' => $name]);
}

it('keys a numbered designation before a lettered one, numbers as numbers', function () {
    expect(UnitDesignationOrder::siblingKey('2/1/A/1-7', 'x'))->toBe([0, 2, ''])
        ->and(UnitDesignationOrder::siblingKey('10/1/A/1-7', 'x'))->toBe([0, 10, ''])
        ->and(UnitDesignationOrder::siblingKey('B/1-7', 'x'))->toBe([1, 0, 'B'])
        ->and(UnitDesignationOrder::siblingKey(null, '1st Section'))->toBe([0, 1, 'ST SECTION']);
});

it('orders siblings by designation whatever their ids, depth first', function () {
    // Bravo was created before Alpha, and 2nd Platoon before 1st: id order would put them first.
    $tree = collect([
        designationUnit(1, '1/', '1-7'),
        designationUnit(5, '1/5/', 'B/1-7'),
        designationUnit(9, '1/9/', 'A/1-7'),
        designationUnit(10, '1/9/10/', '2/A/1-7'),
        designationUnit(11, '1/9/11/', '1/A/1-7'),
        designationUnit(12, '1/9/11/12/', '10/1/A/1-7'),
        designationUnit(13, '1/9/11/13/', '2/1/A/1-7'),
        designationUnit(14, '1/9/10/14/', '1/2/A/1-7'),
        designationUnit(15, '1/5/15/', '1/B/1-7'),
        designationUnit(16, '1/5/15/16/', '1/1/B/1-7'),
    ]);

    $sorted = UnitDesignationOrder::sort($tree->shuffle(), $tree)->pluck('callsign')->all();

    expect($sorted)->toBe(['1-7', 'A/1-7', '1/A/1-7', '2/1/A/1-7', '10/1/A/1-7', '2/A/1-7', '1/2/A/1-7', 'B/1-7', '1/B/1-7', '1/1/B/1-7']);
});

it('sorts a subset by its ancestors\' designations, and breaks a tie by id', function () {
    $tree = collect([
        designationUnit(1, '1/', 'A/1-7'),
        designationUnit(3, '1/3/', '2/A/1-7'),
        designationUnit(4, '1/4/', '1/A/1-7'),
        designationUnit(7, '1/3/7/', '1/2/A/1-7'),
        designationUnit(8, '1/4/8/', '3/1/A/1-7'),
        designationUnit(6, '1/4/6/', '3/1/A/1-7'),
    ]);
    $sections = $tree->whereIn('id', [7, 8, 6])->values();

    expect(UnitDesignationOrder::sort($sections, $tree)->pluck('id')->all())->toBe([6, 8, 7]);
});
