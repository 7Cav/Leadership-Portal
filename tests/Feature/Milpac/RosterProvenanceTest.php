<?php

use App\Milpac\RosterProvenance;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// AD-20, AD-4: the one rule for the roster cache's source and freshness, the same rule LOA uses (LoaProvenance).

function rosterRun(SyncRunStatus $status, $finishedAt, string $source = 'roster'): SyncRun
{
    return SyncRun::factory()->create(['source' => $source, 'status' => $status, 'finished_at' => $finishedAt]);
}

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00:00', 'UTC')));

it('labels the source as milpac', function () {
    expect(app(RosterProvenance::class)->current()->source)->toBe('milpac');
});

it('is fresh when the latest run succeeded inside the threshold', function () {
    rosterRun(SyncRunStatus::Success, CarbonImmutable::parse('2026-10-08 12:00:00'));

    $state = app(RosterProvenance::class)->current();

    expect($state->stale)->toBeFalse()
        ->and($state->lastSyncedAt?->toIso8601String())->toBe('2026-10-08T12:00:00+00:00')
        ->and($state->lastAttempt())->toBeNull()
        ->and($state->state())->toBe('ok');
});

it('serves the last good time, marked stale, when the latest run failed', function () {
    rosterRun(SyncRunStatus::Success, CarbonImmutable::parse('2026-10-08 11:00:00'));
    rosterRun(SyncRunStatus::Failure, CarbonImmutable::parse('2026-10-08 12:00:00'));

    $state = app(RosterProvenance::class)->current();

    expect($state->stale)->toBeTrue()
        ->and($state->lastSyncedAt?->toIso8601String())->toBe('2026-10-08T11:00:00+00:00')
        ->and($state->lastAttempt())->toBe(['status' => 'failure', 'at' => '2026-10-08T12:00:00+00:00'])
        ->and($state->state())->toBe('failed');
});

it('does not call a partial run current, because it skipped rows', function () {
    rosterRun(SyncRunStatus::Success, CarbonImmutable::parse('2026-10-08 11:00:00'));
    rosterRun(SyncRunStatus::Partial, CarbonImmutable::parse('2026-10-08 12:00:00'));

    $state = app(RosterProvenance::class)->current();

    expect($state->stale)->toBeTrue()
        ->and($state->lastAttempt())->toBe(['status' => 'partial', 'at' => '2026-10-08T12:00:00+00:00'])
        ->and($state->state())->toBe('partial');
});

it('calls a success stale once it is older than the threshold, with no failed attempt to name', function () {
    rosterRun(SyncRunStatus::Success, CarbonImmutable::parse('2026-10-08 13:00:00')->subHours(RosterProvenance::STALE_AFTER_HOURS)->subMinute());

    $state = app(RosterProvenance::class)->current();

    expect($state->stale)->toBeTrue()
        ->and($state->lastAttempt())->toBeNull()
        ->and($state->state())->toBe('stale');
});

it('keeps a success inside the threshold fresh', function () {
    rosterRun(SyncRunStatus::Success, CarbonImmutable::parse('2026-10-08 13:00:00')->subHours(RosterProvenance::STALE_AFTER_HOURS)->addMinute());

    expect(app(RosterProvenance::class)->current()->stale)->toBeFalse();
});

it('is an explicit never-synced state when no run has finished', function () {
    $state = app(RosterProvenance::class)->current();

    expect($state->neverSynced())->toBeTrue()
        ->and($state->stale)->toBeFalse()
        ->and($state->state())->toBe('never')
        ->and($state->toArray())->toBe(['source' => 'milpac', 'lastSuccessAt' => null, 'stale' => false, 'lastAttempt' => null]);
});

it('says failed, not never, when every run so far failed', function () {
    rosterRun(SyncRunStatus::Failure, CarbonImmutable::parse('2026-10-08 12:00:00'));

    $state = app(RosterProvenance::class)->current();

    expect($state->neverSynced())->toBeTrue()
        ->and($state->stale)->toBeTrue()
        ->and($state->state())->toBe('failed');
});

it('ignores a run that is still in progress', function () {
    rosterRun(SyncRunStatus::Success, CarbonImmutable::parse('2026-10-08 12:00:00'));
    SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::InProgress, 'finished_at' => null]);

    expect(app(RosterProvenance::class)->current()->state())->toBe('ok');
});

it('reads only the roster source, not the LOA sync', function () {
    rosterRun(SyncRunStatus::Success, CarbonImmutable::parse('2026-10-08 12:00:00'));
    rosterRun(SyncRunStatus::Failure, CarbonImmutable::parse('2026-10-08 12:30:00'), 'loa');

    expect(app(RosterProvenance::class)->current()->state())->toBe('ok');
});

it('never writes while it reads', function () {
    rosterRun(SyncRunStatus::Success, now());

    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|replace|truncate|alter|drop)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    app(RosterProvenance::class)->current();

    expect($writes)->toBe([]);
});
