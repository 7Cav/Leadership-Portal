<?php

use App\Rollcall\ResultRows;

function trooper(array $over = []): array
{
    return [
        'id' => 1, 'cavId' => 100, 'rank' => 'Sergeant', 'rankShort' => 'SGT', 'name' => 'Vasquez.R', 'displayName' => 'SGT Vasquez.R',
        'rollcall' => 'unsigned', 'status' => null, 'source' => null, 'role' => null, 'excused' => null, 'locked' => false,
        ...$over,
    ];
}

it('words each mark as the template does', function () {
    $rows = ResultRows::for([
        trooper(['id' => 1, 'rollcall' => 'will_attend', 'status' => 'present']),
        trooper(['id' => 2, 'rollcall' => 'cannot_attend', 'status' => 'missed']),
        trooper(['id' => 3, 'rollcall' => 'makeup_requested', 'status' => 'makeup']),
        trooper(['id' => 4, 'rollcall' => 'excused', 'status' => 'excused']),
        trooper(['id' => 5]),
    ], false);

    expect(array_column($rows, 'rc'))->toBe(['Yes', 'No', 'MU', 'EX', '-'])
        ->and(array_column($rows, 'sp'))->toBe(['Yes', 'No', 'MU', 'EX', '-']);
});

it('shows a trooper on leave as LOA for practice', function () {
    $rows = ResultRows::for([trooper(['locked' => true])], false);

    expect($rows[0]['sp'])->toBe('LOA');
});

it('counts the automatic excused only where nobody recorded a mark', function () {
    $rows = ResultRows::for([
        trooper(['id' => 1, 'excused' => 'recruit']),
        trooper(['id' => 2, 'excused' => 'recruit', 'rollcall' => 'will_attend', 'status' => 'present', 'source' => 'manual']),
        trooper(['id' => 3, 'excused' => 'transfer', 'status' => 'missed', 'source' => 'auto']),
        trooper(['id' => 4, 'excused' => 'transfer', 'status' => 'present', 'source' => 'auto']),
    ], false);

    expect(array_column($rows, 'rc'))->toBe(['EX', 'Yes', 'EX', 'EX'])
        ->and(array_column($rows, 'sp'))->toBe(['EX', 'Yes', 'EX', 'Yes']);
});

it('lists the section leader first, then by rank, then by name', function () {
    $rows = ResultRows::for([
        trooper(['id' => 1, 'rankShort' => 'PFC', 'name' => 'Adams.A']),
        trooper(['id' => 2, 'rankShort' => 'SGT', 'name' => 'Zed.Z']),
        trooper(['id' => 3, 'rankShort' => 'SPC', 'name' => 'Brown.B', 'role' => 'sl']),
        trooper(['id' => 4, 'rankShort' => 'SGT', 'name' => 'Young.Y']),
    ], false);

    expect(array_column($rows, 'name'))->toBe(['SPC Brown.B', 'SGT Young.Y', 'SGT Zed.Z', 'PFC Adams.A']);
});

it('lists fireteam leaders above the rest only while fireteams are shown', function () {
    $troopers = [
        trooper(['id' => 1, 'rankShort' => 'SGT', 'name' => 'Adams.A']),
        trooper(['id' => 2, 'rankShort' => 'PFC', 'name' => 'Brown.B', 'role' => 'ftl']),
    ];

    expect(array_column(ResultRows::for($troopers, true), 'name'))->toBe(['PFC Brown.B', 'SGT Adams.A'])
        ->and(array_column(ResultRows::for($troopers, false), 'name'))->toBe(['SGT Adams.A', 'PFC Brown.B']);
});

it('breaks ties by initial, then by id, and lists the ASL between the SL and the fireteam leaders', function () {
    $rows = ResultRows::for([
        trooper(['id' => 6, 'rankShort' => 'SGT', 'name' => 'Lee.B']),
        trooper(['id' => 5, 'rankShort' => 'SGT', 'name' => 'Lee.A']),
        trooper(['id' => 4, 'rankShort' => 'SGT', 'name' => 'Lee.A']),
        trooper(['id' => 3, 'rankShort' => 'PFC', 'name' => 'Cole.C', 'role' => 'ftl']),
        trooper(['id' => 2, 'rankShort' => 'PVT', 'name' => 'Dunn.D', 'role' => 'asl']),
        trooper(['id' => 1, 'rankShort' => 'SPC', 'name' => 'Poe.E', 'role' => 'sl']),
    ], true);

    expect(array_column($rows, 'name'))->toBe(['SPC Poe.E', 'PVT Dunn.D', 'PFC Cole.C', 'SGT Lee.A', 'SGT Lee.A', 'SGT Lee.B']);
});

it('lists a rank it does not know below the known ones', function () {
    $rows = ResultRows::for([trooper(['id' => 1, 'rankShort' => 'XYZ', 'name' => 'A.A']), trooper(['id' => 2, 'rankShort' => 'RCT', 'name' => 'B.B'])], false);

    expect(array_column($rows, 'name'))->toBe(['RCT B.B', 'XYZ A.A']);
});

it('names a trooper with no username by their milpac id', function () {
    $rows = ResultRows::for([trooper(['name' => null, 'displayName' => null, 'cavId' => 4821])], false);

    expect($rows[0]['name'])->toBe('SGT #4821');
});
