<?php

use App\Models\TemplateVersion;
use App\Models\Unit;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 5.2 / AD-12, AD-22: one weekly report per (unit, week_start), frozen on send. These tests assert
// the constraints themselves; raw `DB::table()` writes deliberately bypass the model's casts and
// the repository's guards, because the criteria are about the *data layer* refusing a bad row.

function rawWeeklyReportRow(array $overrides = []): array
{
    return array_merge([
        'unit_id' => Unit::factory()->create()->id,
        'week_start' => '2026-10-03 00:00:00',
        'status' => 'draft',
        'kpis' => json_encode(['strength' => ['value' => 9]]),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

it('creates weekly_reports with the key, status, snapshot, send metadata and template reference columns', function () {
    expect(Schema::hasColumns('weekly_reports', [
        'id', 'unit_id', 'week_start', 'status', 'kpis', 'notes', 'sent_at', 'sent_by',
        'content_digest', 'template_version_id', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('makes (unit_id, week_start) unique', function () {
    $uniques = collect(Schema::getIndexes('weekly_reports'))
        ->filter(fn (array $index) => $index['unique'] && ! $index['primary'])
        ->map(fn (array $index) => $index['columns'])
        ->values()
        ->all();

    expect($uniques)->toContain(['unit_id', 'week_start']);
});

it('points unit_id at units, sent_by and sent_by_admin at users and template_version_id at template_versions', function () {
    $targets = collect(Schema::getForeignKeys('weekly_reports'))
        ->mapWithKeys(fn (array $fk) => [$fk['columns'][0] => $fk['foreign_table'].'.'.$fk['foreign_columns'][0]])
        ->sortKeys()
        ->all();

    expect($targets)->toBe([
        'sent_by' => 'users.id',
        // The Admin who sent it while acting as `sent_by` (#379).
        'sent_by_admin' => 'users.id',
        'template_version_id' => 'template_versions.id',
        'unit_id' => 'units.id',
    ]);
});

it('rejects a second row for the same unit and week — the substrate of an idempotent send', function () {
    $row = rawWeeklyReportRow();
    DB::table('weekly_reports')->insert($row);

    expect(fn () => DB::table('weekly_reports')->insert(array_merge($row, ['status' => 'sent'])))
        ->toThrow(QueryException::class)
        ->and(DB::table('weekly_reports')->count())->toBe(1);
});

it('keeps separate rows per unit and per week', function () {
    $row = rawWeeklyReportRow();
    DB::table('weekly_reports')->insert($row);
    DB::table('weekly_reports')->insert(array_merge($row, ['week_start' => '2026-10-10 00:00:00']));
    DB::table('weekly_reports')->insert(array_merge($row, ['unit_id' => Unit::factory()->create()->id]));

    expect(DB::table('weekly_reports')->count())->toBe(3);
});

it('rejects a status outside draft/sent at the data layer', function () {
    expect(fn () => DB::table('weekly_reports')->insert(rawWeeklyReportRow(['status' => 'archived'])))
        ->toThrow(QueryException::class);
});

it('accepts every PHP status case at the DB — the hand-mirrored enum values have not drifted', function () {
    foreach (WeeklyReportStatus::cases() as $status) {
        DB::table('weekly_reports')->insert(rawWeeklyReportRow(['status' => $status->value]));
    }

    expect(DB::table('weekly_reports')->count())->toBe(count(WeeklyReportStatus::cases()));
});

it('defaults a row to draft with null notes, send metadata and template reference', function () {
    $row = rawWeeklyReportRow();
    unset($row['status']);
    DB::table('weekly_reports')->insert($row);

    $raw = DB::table('weekly_reports')->sole();

    expect($raw->status)->toBe('draft')
        ->and($raw->notes)->toBeNull()
        ->and($raw->sent_at)->toBeNull()
        ->and($raw->sent_by)->toBeNull()
        ->and($raw->content_digest)->toBeNull()
        ->and($raw->template_version_id)->toBeNull();
});

it('rejects a template_version_id that names no template version', function () {
    expect(fn () => DB::table('weekly_reports')->insert(rawWeeklyReportRow(['template_version_id' => 424242])))
        ->toThrow(QueryException::class)
        ->and(DB::table('weekly_reports')->count())->toBe(0);
});

it('accepts a template_version_id that names a template version', function () {
    $version = TemplateVersion::factory()->create();

    DB::table('weekly_reports')->insert(rawWeeklyReportRow(['template_version_id' => $version->id]));

    expect(DB::table('weekly_reports')->sole()->template_version_id)->toBe($version->id);
});

it('keeps status and send metadata out of mass assignment — only the repository send path sets them', function () {
    expect((new WeeklyReport)->getFillable())->toBe(['unit_id', 'week_start', 'kpis', 'notes']);
});

it('prevents deleting a unit that still has weekly reports', function () {
    $report = WeeklyReport::factory()->create();

    expect(fn () => Unit::query()->whereKey($report->unit_id)->delete())->toThrow(QueryException::class)
        ->and(WeeklyReport::query()->count())->toBe(1);
});

it('prevents deleting the user recorded as a report sender', function () {
    $report = WeeklyReport::factory()->sent()->create();

    expect(fn () => User::query()->whereKey($report->sent_by)->delete())->toThrow(QueryException::class)
        ->and(WeeklyReport::query()->sole()->sent_by)->toBe($report->sent_by);
});

it('prevents deleting a template version a report was sent with', function () {
    $version = TemplateVersion::factory()->create();
    DB::table('weekly_reports')->insert(rawWeeklyReportRow(['status' => 'sent', 'template_version_id' => $version->id]));

    expect(fn () => TemplateVersion::query()->whereKey($version->id)->delete())->toThrow(QueryException::class)
        ->and(DB::table('weekly_reports')->sole()->template_version_id)->toBe($version->id);
});
