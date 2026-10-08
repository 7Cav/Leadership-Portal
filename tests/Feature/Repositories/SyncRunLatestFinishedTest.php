<?php

use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Repositories\SyncRunRepository;

// Story 2.8: the latest run that closed, whatever its outcome, to compare against the last success.

it('returns the most recent finished run whatever its outcome', function () {
    SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Success, 'finished_at' => now()->subHours(2)]);
    $failed = SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Failure, 'finished_at' => now()->subHour()]);

    expect(app(SyncRunRepository::class)->latestFinished('roster')?->id)->toBe($failed->id);
});

it('skips a run that is still in progress', function () {
    $done = SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Partial, 'finished_at' => now()->subHour()]);
    SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::InProgress, 'finished_at' => null]);

    expect(app(SyncRunRepository::class)->latestFinished('roster')?->id)->toBe($done->id);
});

it('breaks a same-second tie on id', function () {
    $second = now()->startOfSecond();
    SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Success, 'finished_at' => $second]);
    $later = SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Failure, 'finished_at' => $second]);

    expect(app(SyncRunRepository::class)->latestFinished('roster')?->id)->toBe($later->id);
});

it('is scoped to the source and null when there is no run', function () {
    SyncRun::factory()->create(['source' => 'loa', 'status' => SyncRunStatus::Success, 'finished_at' => now()]);

    expect(app(SyncRunRepository::class)->latestFinished('roster'))->toBeNull();
});
