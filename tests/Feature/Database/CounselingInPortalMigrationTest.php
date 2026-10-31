<?php

use App\Models\CounselingRecord;
use App\Models\CounselingSource;
use App\Models\CounselingStatus;
use App\Models\Trooper;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// #565: records already in the portal keep their uploaded PDF as their source document and become shared and
// acknowledged when they were filed, so their windows and their freeze run as before. The Drive columns are gone.

function counselingInPortalMigration(): object
{
    return require database_path('migrations/2026_11_08_100000_write_counseling_records_in_the_portal.php');
}

it('turns every record filed before into a shared, acknowledged upload, and drops the Drive columns', function () {
    counselingInPortalMigration()->down();
    $filed = CarbonImmutable::parse('2026-10-20 18:00:00');
    $id = DB::table('counseling_records')->insertGetId([
        'trooper_id' => Trooper::factory()->create()->id,
        'type' => 'lor',
        'counseled_on' => '2026-10-19',
        'reason' => 'Filed with a PDF.',
        'issued_by' => User::factory()->create()->id,
        'document_path' => '0b7f4d0e-6c3c-4c8e-9a51-0f6f3d6c1a11.pdf',
        'document_name' => 'lor.pdf',
        'document_size' => 1024,
        'document_sha256' => str_repeat('a', 64),
        'drive_status' => 'uploaded',
        'created_at' => $filed,
        'updated_at' => $filed,
    ]);

    counselingInPortalMigration()->up();

    $record = CounselingRecord::query()->findOrFail($id);
    expect($record->status)->toBe(CounselingStatus::Shared)
        ->and($record->source)->toBe(CounselingSource::Upload)
        ->and($record->shared_at->toDateTimeString())->toBe('2026-10-20 18:00:00')
        ->and($record->responded_at->toDateTimeString())->toBe('2026-10-20 18:00:00')
        ->and($record->hasSourceDocument())->toBeTrue()
        ->and($record->version)->toBe(1)
        ->and($record->state(CarbonImmutable::parse('2026-10-30')))->toBe('acknowledged')
        ->and($record->isFrozen(CarbonImmutable::parse('2026-10-23 18:00:00')))->toBeTrue()
        ->and(Schema::hasColumn('counseling_records', 'drive_status'))->toBeFalse()
        ->and(Schema::hasColumn('counseling_records', 'drive_file_id'))->toBeFalse();
});

it('rolls back to the Drive-era shape, dropping only what that shape cannot hold: a draft or a record written in the portal', function () {
    $upload = CounselingRecord::factory()->create();
    CounselingRecord::factory()->draft()->create();
    CounselingRecord::factory()->written()->create();

    counselingInPortalMigration()->down();

    expect(DB::table('counseling_records')->pluck('id')->all())->toBe([$upload->id])
        ->and(Schema::hasColumn('counseling_records', 'drive_status'))->toBeTrue()
        ->and(Schema::hasColumn('counseling_records', 'status'))->toBeFalse();

    counselingInPortalMigration()->up();
});
