<?php

use App\Loa\LoaProvenance;
use App\Models\Loa;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Story 4.3: the source and last-sync every LOA value renders with (AD-20), and staleness shown, never hidden (AD-4).

function loaRun(SyncRunStatus $status, $finishedAt, string $source = 'loa'): SyncRun
{
    return SyncRun::factory()->create(['source' => $source, 'status' => $status, 'finished_at' => $finishedAt]);
}

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00:00', 'UTC')));

it('labels the source as the forum thread', function () {
    expect(app(LoaProvenance::class)->current()->source)->toBe('Forum thread');
});

it('reports the last successful sync time when the latest run succeeded', function () {
    loaRun(SyncRunStatus::Success, CarbonImmutable::parse('2026-10-08 12:00:00'));

    $state = app(LoaProvenance::class)->current();

    expect($state->neverSynced())->toBeFalse()
        ->and($state->lastSyncedAt?->toIso8601String())->toBe('2026-10-08T12:00:00+00:00')
        ->and($state->stale)->toBeFalse()
        ->and($state->latestStatus)->toBe('success');
});

it('serves the last good time, marked stale, when the latest sync failed', function () {
    loaRun(SyncRunStatus::Success, CarbonImmutable::parse('2026-10-01 12:00:00'));
    loaRun(SyncRunStatus::Failure, CarbonImmutable::parse('2026-10-08 12:00:00'));

    $state = app(LoaProvenance::class)->current();

    expect($state->stale)->toBeTrue()
        ->and($state->latestStatus)->toBe('failure')
        ->and($state->lastSyncedAt?->toDateString())->toBe('2026-10-01')
        ->and($state->latestFinishedAt?->toDateString())->toBe('2026-10-08')
        ->and($state->neverSynced())->toBeFalse();
});

it('does not call a partial run current, because it left threads unread', function () {
    loaRun(SyncRunStatus::Success, CarbonImmutable::parse('2026-10-01 12:00:00'));
    loaRun(SyncRunStatus::Partial, CarbonImmutable::parse('2026-10-08 12:00:00'));

    $state = app(LoaProvenance::class)->current();

    expect($state->stale)->toBeTrue()
        ->and($state->latestStatus)->toBe('partial')
        ->and($state->lastSyncedAt?->toDateString())->toBe('2026-10-01');
});

it('is an explicit never-synced state when no run has finished, not a blank or a made-up time', function () {
    $state = app(LoaProvenance::class)->current();

    expect($state->neverSynced())->toBeTrue()
        ->and($state->lastSyncedAt)->toBeNull()
        ->and($state->stale)->toBeFalse()
        ->and($state->latestStatus)->toBeNull()
        ->and($state->source)->toBe('Forum thread');
});

it('is never-synced, and stale, when every run so far failed', function () {
    loaRun(SyncRunStatus::Failure, CarbonImmutable::parse('2026-10-08 12:00:00'));

    $state = app(LoaProvenance::class)->current();

    expect($state->neverSynced())->toBeTrue()
        ->and($state->stale)->toBeTrue()
        ->and($state->latestStatus)->toBe('failure');
});

it('calls a success stale once it is older than the threshold, because syncs that stopped must not look fresh', function () {
    loaRun(SyncRunStatus::Success, CarbonImmutable::parse('2026-10-08 13:00:00')->subHours(LoaProvenance::STALE_AFTER_HOURS)->subMinute());

    $state = app(LoaProvenance::class)->current();

    expect($state->stale)->toBeTrue()
        ->and($state->latestStatus)->toBe('success')
        ->and($state->neverSynced())->toBeFalse();
});

it('keeps a success inside the threshold fresh', function () {
    loaRun(SyncRunStatus::Success, CarbonImmutable::parse('2026-10-08 13:00:00')->subHours(LoaProvenance::STALE_AFTER_HOURS)->addMinute());

    expect(app(LoaProvenance::class)->current()->stale)->toBeFalse();
});

it('ignores a run that is still in progress', function () {
    loaRun(SyncRunStatus::Success, CarbonImmutable::parse('2026-10-08 10:00:00'));
    SyncRun::factory()->create(['source' => 'loa', 'status' => SyncRunStatus::InProgress, 'finished_at' => null]);

    $state = app(LoaProvenance::class)->current();

    expect($state->stale)->toBeFalse()
        ->and($state->latestStatus)->toBe('success');
});

it('reads only the LOA source, not the roster sync', function () {
    loaRun(SyncRunStatus::Success, CarbonImmutable::parse('2026-10-08 12:00:00'), 'roster');

    expect(app(LoaProvenance::class)->current()->neverSynced())->toBeTrue();

    loaRun(SyncRunStatus::Failure, CarbonImmutable::parse('2026-10-09 12:00:00'), 'roster');
    loaRun(SyncRunStatus::Success, CarbonImmutable::parse('2026-10-08 11:00:00'));

    expect(app(LoaProvenance::class)->current()->stale)->toBeFalse();
});

it('exposes the same shape the pages read, with ISO times', function () {
    loaRun(SyncRunStatus::Success, CarbonImmutable::parse('2026-10-01 12:00:00'));
    loaRun(SyncRunStatus::Partial, CarbonImmutable::parse('2026-10-08 12:00:00'));

    expect(app(LoaProvenance::class)->current()->toArray())->toBe([
        'source' => 'Forum thread',
        'lastSyncedAt' => '2026-10-01T12:00:00+00:00',
        'stale' => true,
        'neverSynced' => false,
        'latestStatus' => 'partial',
        'latestFinishedAt' => '2026-10-08T12:00:00+00:00',
        'backfilling' => false,
        'backfilledTo' => null,
    ]);
});

it('says the first read of the year is still going, and how far back it has got, from the latest cursor', function () {
    loaRun(SyncRunStatus::Success, CarbonImmutable::parse('2026-10-08 11:00:00'));
    $run = loaRun(SyncRunStatus::Success, CarbonImmutable::parse('2026-10-08 12:00:00'));
    $run->forceFill(['cursor' => ['sections' => [], 'retry' => [], 'backfilling' => true, 'reachedBack' => '2026-04-02T08:00:00+00:00']])->save();

    $state = app(LoaProvenance::class)->current();

    expect($state->backfilling)->toBeTrue()
        ->and($state->backfilledTo?->toIso8601String())->toBe('2026-04-02T08:00:00+00:00')
        ->and($state->stale)->toBeFalse()
        ->and($state->toArray()['backfilledTo'])->toBe('2026-04-02T08:00:00+00:00');
});

it('stops saying so once the cursor says the first read is done', function () {
    $run = loaRun(SyncRunStatus::Success, CarbonImmutable::parse('2026-10-08 12:00:00'));
    $run->forceFill(['cursor' => ['sections' => [], 'retry' => [], 'backfilling' => false, 'reachedBack' => null]])->save();

    $state = app(LoaProvenance::class)->current();

    expect($state->backfilling)->toBeFalse()->and($state->backfilledTo)->toBeNull();
});

it('keeps saying so after a full run, which saves no cursor of its own', function () {
    $hourly = loaRun(SyncRunStatus::Success, CarbonImmutable::parse('2026-10-08 11:30:00'));
    $hourly->forceFill(['started_at' => CarbonImmutable::parse('2026-10-08 11:00:00'), 'cursor' => ['sections' => [], 'retry' => [], 'backfilling' => true, 'reachedBack' => '2026-06-01T00:00:00+00:00']])->save();
    SyncRun::factory()->create(['source' => 'loa', 'started_at' => CarbonImmutable::parse('2026-10-08 12:00:00'), 'finished_at' => CarbonImmutable::parse('2026-10-08 12:30:00')]);

    expect(app(LoaProvenance::class)->current()->backfilling)->toBeTrue();
});

it('never writes loas or sync_runs while it reads', function () {
    Loa::factory()->create();
    loaRun(SyncRunStatus::Success, now());

    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|replace|truncate|alter|drop)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    app(LoaProvenance::class)->current();

    expect($writes)->toBe([]);
});
