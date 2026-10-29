<?php

use App\Loa\LoaSyncProblems;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use Carbon\CarbonImmutable;

// What the latest LOA sync could not read (2026-10-29), from what the sync already records in `sync_runs`: the
// problems it joined into one text, its needs-review count, and why a failed run stopped. Never the forum.

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00:00', 'UTC')));

it('is null before any LOA sync has finished', function () {
    SyncRun::factory()->create(['source' => SyncRun::SOURCE_ROSTER, 'finished_at' => '2026-10-08 12:00:00', 'errored' => 2]);

    expect(app(LoaSyncProblems::class)->latest())->toBeNull();
});

it('splits the latest run\'s problems back apart, keeping each thread id, with its counts', function () {
    SyncRun::factory()->create(['source' => SyncRun::SOURCE_LOA, 'status' => SyncRunStatus::Partial, 'finished_at' => '2026-10-08 11:00:00', 'errored' => 1, 'error' => 'Thread 1: old']);
    SyncRun::factory()->create([
        'source' => SyncRun::SOURCE_LOA, 'status' => SyncRunStatus::Partial, 'finished_at' => '2026-10-08 12:15:00', 'errored' => 3, 'needs_review' => 4,
        'error' => 'Thread 812: The forum answered 503.; Thread 813: PAF 90: The forum answered 500.; Section 180: stopped at the 50-page limit before reaching the cutoff',
    ]);

    expect(app(LoaSyncProblems::class)->latest())->toBe([
        'status' => 'partial',
        'finishedAt' => '2026-10-08T12:15:00+00:00',
        'unread' => 3,
        'needsReview' => 4,
        'failure' => null,
        'problems' => [
            ['threadId' => 812, 'text' => 'Thread 812: The forum answered 503.'],
            ['threadId' => 813, 'text' => 'Thread 813: PAF 90: The forum answered 500.'],
            ['threadId' => null, 'text' => 'Section 180: stopped at the 50-page limit before reaching the cutoff'],
        ],
    ]);
});

it('gives a failed run\'s reason as the failure, not as a thread', function () {
    SyncRun::factory()->create(['source' => SyncRun::SOURCE_LOA, 'status' => SyncRunStatus::Failure, 'finished_at' => '2026-10-08 12:15:00', 'error' => 'The forum answered 401.']);

    expect(app(LoaSyncProblems::class)->latest())
        ->toMatchArray(['status' => 'failure', 'failure' => 'The forum answered 401.', 'problems' => []]);
});

it('lists at most 20 problems, the count still saying how many', function () {
    $error = implode('; ', array_map(fn (int $id) => "Thread {$id}: The forum answered 503.", range(1, 25)));
    SyncRun::factory()->create(['source' => SyncRun::SOURCE_LOA, 'status' => SyncRunStatus::Partial, 'finished_at' => '2026-10-08 12:15:00', 'errored' => 25, 'error' => $error]);

    $latest = app(LoaSyncProblems::class)->latest();

    expect($latest['problems'])->toHaveCount(20)->and($latest['unread'])->toBe(25);
});
