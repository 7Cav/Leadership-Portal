<?php

use App\Models\CrossSectionSighting;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The JSAM guests widening (2026-10-23): `source` gains `manual`, `recorded_by` names the leader, and the capture window
// becomes nullable for a hand entry. Rolling it back is refused while a hand entry exists, like the WARDOGS rollback.

function manualGuestsMigration(): object
{
    return require database_path('migrations/2026_10_23_110000_add_manual_guests_to_cross_section_sightings_table.php');
}

function captureSightingRow(): CrossSectionSighting
{
    return CrossSectionSighting::query()->create([
        'trooper_id' => Trooper::factory()->create()->id,
        'section_practice_id' => SectionPractice::factory()->create(['week_start' => '2026-10-03 00:00:00'])->id,
        'week_start' => '2026-10-03 00:00:00',
        'seen_from' => '2026-10-08 18:00:00',
        'seen_until' => '2026-10-08 19:30:00',
        'source' => CrossSectionSighting::SOURCE_CAPTURE,
    ]);
}

it('takes a hand entry with no window and the leader who added it', function () {
    $row = CrossSectionSighting::query()->create([
        'trooper_id' => Trooper::factory()->create()->id,
        'section_practice_id' => SectionPractice::factory()->create(['week_start' => '2026-10-03 00:00:00'])->id,
        'week_start' => '2026-10-03 00:00:00',
        'source' => CrossSectionSighting::SOURCE_MANUAL,
        'recorded_by' => User::factory()->create()->id,
    ])->fresh();

    expect($row->source)->toBe('manual')->and($row->seen_from)->toBeNull()->and($row->recorded_by)->not->toBeNull();
});

it('keeps the entry when the leader who added it is deleted, naming nobody', function () {
    $leader = User::factory()->create();
    $row = CrossSectionSighting::query()->create([
        'trooper_id' => Trooper::factory()->create()->id,
        'section_practice_id' => SectionPractice::factory()->create(['week_start' => '2026-10-03 00:00:00'])->id,
        'week_start' => '2026-10-03 00:00:00',
        'source' => CrossSectionSighting::SOURCE_MANUAL,
        'recorded_by' => $leader->id,
    ]);

    $leader->delete();

    expect($row->fresh()->recorded_by)->toBeNull();
});

it('refuses to roll back while a hand entry exists, and leaves it in place', function () {
    captureSightingRow();
    DB::table('cross_section_sightings')->insert([
        'trooper_id' => Trooper::factory()->create()->id,
        'section_practice_id' => SectionPractice::factory()->create(['week_start' => '2026-10-03 00:00:00'])->id,
        'week_start' => '2026-10-03 00:00:00',
        'source' => 'manual',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => manualGuestsMigration()->down())->toThrow(RuntimeException::class, '1 cross_section_sightings row(s) were recorded by a leader');

    expect(CrossSectionSighting::query()->count())->toBe(2)
        ->and(Schema::hasColumn('cross_section_sightings', 'recorded_by'))->toBeTrue();
});

it('rolls back to capture-only and reapplies, keeping the capture sightings', function () {
    $capture = captureSightingRow();

    manualGuestsMigration()->down();

    expect(Schema::hasColumn('cross_section_sightings', 'recorded_by'))->toBeFalse()
        ->and($capture->fresh()->seen_from->toIso8601String())->toBe('2026-10-08T18:00:00+00:00');
    // The narrowed column takes a capture row only, with its window.
    expect(fn () => DB::table('cross_section_sightings')->insert([
        'trooper_id' => Trooper::factory()->create()->id,
        'section_practice_id' => $capture->section_practice_id,
        'week_start' => '2026-10-03 00:00:00',
        'seen_from' => '2026-10-08 18:00:00',
        'seen_until' => '2026-10-08 19:30:00',
        'source' => 'manual',
    ]))->toThrow(QueryException::class);

    manualGuestsMigration()->up();

    expect(Schema::hasColumn('cross_section_sightings', 'recorded_by'))->toBeTrue()
        ->and($capture->fresh()->source)->toBe('capture');
});
