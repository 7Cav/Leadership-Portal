<?php

use App\Models\CrossSectionSighting;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// #448: `source` gains `staff` for the practices a staff member attended. Rolling it back is refused while one exists.

function staffSourceMigration(): object
{
    return require database_path('migrations/2026_10_31_100000_add_staff_source_to_cross_section_sightings.php');
}

function staffEntryRow(): CrossSectionSighting
{
    return CrossSectionSighting::query()->create([
        'trooper_id' => Trooper::factory()->create()->id,
        'section_practice_id' => SectionPractice::factory()->create(['week_start' => '2026-10-03 00:00:00'])->id,
        'week_start' => '2026-10-03 00:00:00',
        'source' => CrossSectionSighting::SOURCE_STAFF,
        'recorded_by' => User::factory()->create()->id,
    ]);
}

it('takes a staff entry, and still refuses a source it does not know', function () {
    expect(staffEntryRow()->fresh()->source)->toBe('staff');

    expect(fn () => DB::table('cross_section_sightings')->insert([
        'trooper_id' => Trooper::factory()->create()->id,
        'section_practice_id' => SectionPractice::factory()->create(['week_start' => '2026-10-03 00:00:00'])->id,
        'week_start' => '2026-10-03 00:00:00',
        'source' => 'rumour',
    ]))->toThrow(QueryException::class);
});

it('refuses to roll back while a staff entry exists, and leaves it in place', function () {
    staffEntryRow();

    expect(fn () => staffSourceMigration()->down())->toThrow(RuntimeException::class, 'Cannot roll back staff practice entries: 1');
    expect(CrossSectionSighting::query()->sole()->source)->toBe('staff');
});

it('rolls back cleanly with no staff entry', function () {
    staffSourceMigration()->down();

    expect(fn () => staffEntryRow())->toThrow(QueryException::class);

    staffSourceMigration()->up();
    expect(staffEntryRow()->source)->toBe('staff');
});
