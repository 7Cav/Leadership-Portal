<?php

use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 3.1 / AD-14: the attendance grain is fixed in schema — one practice row per (section, week),
// one attendance row per (trooper, practice) — so these tests assert the constraints themselves,
// not just that rows land. Raw `DB::table()` writes deliberately bypass the model's enum casts:
// the enum-integrity criterion is about the *data layer* refusing a bad value.

function uniqueColumnSets(string $table): array
{
    return collect(Schema::getIndexes($table))
        ->filter(fn (array $index) => $index['unique'] && ! $index['primary'])
        ->map(fn (array $index) => $index['columns'])
        ->values()
        ->all();
}

function foreignKeyTargets(string $table): array
{
    return collect(Schema::getForeignKeys($table))
        ->mapWithKeys(fn (array $fk) => [$fk['columns'][0] => $fk['foreign_table'].'.'.$fk['foreign_columns'][0]])
        ->all();
}

function rawAttendanceRow(array $overrides = []): array
{
    $section = Unit::factory()->create(['type' => UnitType::Section]);
    $practice = SectionPractice::factory()->create(['section_id' => $section->id]);

    return array_merge([
        'trooper_id' => Trooper::factory()->create()->id,
        'section_practice_id' => $practice->id,
        'status' => 'present',
        'source' => 'manual',
        'rollcall' => 'unsigned',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

it('creates section_practices with the grain columns and the null auto-capture seam', function () {
    expect(Schema::hasColumns('section_practices', [
        'id', 'section_id', 'week_start', 'server_ref', 'captured_at', 'capture_source', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('creates attendance with the trooper, practice, status, source and rollcall columns', function () {
    expect(Schema::hasColumns('attendance', [
        'id', 'trooper_id', 'section_practice_id', 'status', 'source', 'rollcall', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('makes (section_id, week_start) unique on section_practices', function () {
    expect(uniqueColumnSets('section_practices'))->toContain(['section_id', 'week_start']);
});

it('makes (trooper_id, section_practice_id) unique on attendance', function () {
    expect(uniqueColumnSets('attendance'))->toContain(['trooper_id', 'section_practice_id']);
});

it('points section_practices.section_id at units and attendance at troopers and section_practices', function () {
    expect(foreignKeyTargets('section_practices'))->toMatchArray(['section_id' => 'units.id'])
        ->and(foreignKeyTargets('attendance'))->toMatchArray([
            'trooper_id' => 'troopers.id',
            'section_practice_id' => 'section_practices.id',
        ]);
});

it('rejects a second practice row for the same section and week', function () {
    $practice = SectionPractice::factory()->create();

    expect(fn () => DB::table('section_practices')->insert([
        'section_id' => $practice->section_id,
        'week_start' => $practice->getRawOriginal('week_start'),
    ]))->toThrow(QueryException::class);
});

it('rejects a second attendance row for the same trooper and practice', function () {
    $row = rawAttendanceRow();
    DB::table('attendance')->insert($row);

    expect(fn () => DB::table('attendance')->insert(array_merge($row, ['status' => 'missed'])))
        ->toThrow(QueryException::class);
});

it('leaves the auto-capture seam columns null on a practice row', function () {
    $practice = SectionPractice::factory()->create();

    $raw = DB::table('section_practices')->where('id', $practice->id)->sole();

    expect($raw->server_ref)->toBeNull()
        ->and($raw->captured_at)->toBeNull()
        ->and($raw->capture_source)->toBeNull();
});

it('accepts a practice row with zero attendance rows — an empty roster is valid', function () {
    $practice = SectionPractice::factory()->create();

    expect(SectionPractice::query()->whereKey($practice->id)->exists())->toBeTrue()
        ->and(Attendance::query()->where('section_practice_id', $practice->id)->count())->toBe(0);
});

it('rejects a status outside the five allowed values at the data layer', function () {
    expect(fn () => DB::table('attendance')->insert(rawAttendanceRow(['status' => 'attached'])))
        ->toThrow(QueryException::class);
});

it('rejects a source outside manual/auto at the data layer', function () {
    expect(fn () => DB::table('attendance')->insert(rawAttendanceRow(['source' => 'imported'])))
        ->toThrow(QueryException::class);
});

it('rejects a rollcall value outside unsigned/will_attend/cannot_attend at the data layer', function () {
    expect(fn () => DB::table('attendance')->insert(rawAttendanceRow(['rollcall' => 'maybe'])))
        ->toThrow(QueryException::class);
});

it('accepts a null status and source — a trooper can sign the rollcall before the practice is marked', function () {
    $row = rawAttendanceRow(['status' => null, 'source' => null, 'rollcall' => 'will_attend']);

    DB::table('attendance')->insert($row);

    $raw = DB::table('attendance')->sole();

    expect($raw->status)->toBeNull()
        ->and($raw->source)->toBeNull();
});

it('defaults rollcall to unsigned when a row omits it', function () {
    $row = rawAttendanceRow();
    unset($row['rollcall']);

    DB::table('attendance')->insert($row);

    expect(DB::table('attendance')->sole()->rollcall)->toBe('unsigned');
});

it('accepts every PHP enum case at the DB — the hand-mirrored enum values have not drifted', function () {
    $section = Unit::factory()->create(['type' => UnitType::Section]);
    $practice = SectionPractice::factory()->create(['section_id' => $section->id]);

    foreach (AttendanceStatus::cases() as $status) {
        foreach (AttendanceSource::cases() as $source) {
            foreach (RollcallSignature::cases() as $rollcall) {
                DB::table('attendance')->insert([
                    'trooper_id' => Trooper::factory()->create()->id,
                    'section_practice_id' => $practice->id,
                    'status' => $status->value,
                    'source' => $source->value,
                    'rollcall' => $rollcall->value,
                ]);
            }
        }
    }

    expect(DB::table('attendance')->count())
        ->toBe(count(AttendanceStatus::cases()) * count(AttendanceSource::cases()) * count(RollcallSignature::cases()));
});

it('prevents deleting a section that still has practice and attendance rows', function () {
    $row = rawAttendanceRow();
    DB::table('attendance')->insert($row);
    $sectionId = SectionPractice::query()->findOrFail($row['section_practice_id'])->section_id;

    expect(fn () => Unit::query()->whereKey($sectionId)->delete())->toThrow(QueryException::class)
        ->and(Unit::query()->whereKey($sectionId)->exists())->toBeTrue()
        ->and(DB::table('attendance')->count())->toBe(1);
});

it('prevents deleting a practice row that still has attendance', function () {
    $row = rawAttendanceRow();
    DB::table('attendance')->insert($row);

    expect(fn () => SectionPractice::query()->whereKey($row['section_practice_id'])->delete())
        ->toThrow(QueryException::class);
});

it('prevents deleting a trooper that still has attendance', function () {
    $row = rawAttendanceRow();
    DB::table('attendance')->insert($row);

    expect(fn () => Trooper::query()->whereKey($row['trooper_id'])->delete())
        ->toThrow(QueryException::class);
});
