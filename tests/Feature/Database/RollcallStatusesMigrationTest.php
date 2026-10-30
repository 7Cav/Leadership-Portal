<?php

use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\Trooper;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The roll call statuses of 2026-10-30: practice `attached` merges into `makeup` and `excused` is new; the signature
// gains `makeup_requested` and `excused`. The rows that were `attached` are kept by id, so a rollback puts them back.

function rollcallStatusesMigration(): object
{
    return require database_path('migrations/2026_11_07_100000_rework_attendance_statuses.php');
}

function rawMark(string $status, string $rollcall = 'will_attend'): int
{
    return DB::table('attendance')->insertGetId([
        'trooper_id' => Trooper::factory()->create()->id,
        'section_practice_id' => SectionPractice::factory()->create()->id,
        'status' => $status,
        'source' => 'manual',
        'rollcall' => $rollcall,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('takes the new statuses and refuses the retired attached', function () {
    $excused = rawMark('excused', 'excused');
    $requested = rawMark('makeup', 'makeup_requested');

    expect(Attendance::query()->find($excused)->status)->toBe(AttendanceStatus::Excused)
        ->and(Attendance::query()->find($excused)->rollcall)->toBe(RollcallSignature::Excused)
        ->and(Attendance::query()->find($requested)->rollcall)->toBe(RollcallSignature::MakeupRequested);

    expect(fn () => rawMark('attached'))->toThrow(QueryException::class);
});

it('keeps the CHECK on the source column through the rebuild', function () {
    expect(fn () => DB::table('attendance')->insert([
        'trooper_id' => Trooper::factory()->create()->id,
        'section_practice_id' => SectionPractice::factory()->create()->id,
        'status' => 'present',
        'source' => 'robot',
        'rollcall' => 'unsigned',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('merges attached rows into make-up complete, keeps their ids, and puts them back on rollback', function () {
    rollcallStatusesMigration()->down();
    expect(Schema::hasTable('attendance_attached_rows'))->toBeFalse();

    $attached = rawMark('attached');
    $changedSince = rawMark('attached');
    $present = rawMark('present');
    $makeup = rawMark('makeup');

    rollcallStatusesMigration()->up();

    expect(DB::table('attendance')->where('id', $attached)->value('status'))->toBe('makeup')
        ->and(DB::table('attendance')->where('id', $present)->value('status'))->toBe('present')
        ->and(DB::table('attendance')->where('id', $makeup)->value('status'))->toBe('makeup')
        ->and(DB::table('attendance_attached_rows')->pluck('attendance_id')->sort()->values()->all())->toBe(collect([$attached, $changedSince])->sort()->values()->all())
        ->and(DB::table('attendance')->where('status', 'attached')->count())->toBe(0);

    // A leader corrects one of them after the deploy: the rollback keeps that newer mark.
    DB::table('attendance')->where('id', $changedSince)->update(['status' => 'missed']);

    rollcallStatusesMigration()->down();

    expect(DB::table('attendance')->where('id', $attached)->value('status'))->toBe('attached')
        ->and(DB::table('attendance')->where('id', $changedSince)->value('status'))->toBe('missed')
        ->and(DB::table('attendance')->where('id', $makeup)->value('status'))->toBe('makeup')
        ->and(Schema::hasTable('attendance_attached_rows'))->toBeFalse();

    // Reapplied, the tree is as the deploy leaves it.
    rollcallStatusesMigration()->up();
    expect(DB::table('attendance')->where('id', $attached)->value('status'))->toBe('makeup');
});

it('refuses to roll back while a row is Excused or Make-up requested, and changes nothing', function (string $status, string $rollcall) {
    $id = rawMark($status, $rollcall);

    expect(fn () => rollcallStatusesMigration()->down())->toThrow(RuntimeException::class, 'Excused or Make-up requested');

    expect((array) DB::table('attendance')->where('id', $id)->first())->toMatchArray(['status' => $status, 'rollcall' => $rollcall])
        ->and(Schema::hasTable('attendance_attached_rows'))->toBeTrue();
})->with([
    'excused practice' => ['excused', 'will_attend'],
    'excused signature' => ['present', 'excused'],
    'make-up requested' => ['missed', 'makeup_requested'],
]);
