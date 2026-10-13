<?php

use App\Models\SyncRunStatus;
use App\Repositories\SyncRunRepository;

// Story 4.2: rows left for a person are counted apart from errors, so an unreadable post does not make a run partial.

it('records how many rows a run left for a person, without making the run partial', function () {
    $repo = app(SyncRunRepository::class);
    $run = $repo->open('loa', 'system');

    $repo->complete($run, inserted: 5, updated: 1, inactivated: 0, errored: 0, needsReview: 3);

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(SyncRunStatus::Success)
        ->and($fresh->needs_review)->toBe(3)
        ->and($fresh->errored)->toBe(0)
        ->and($repo->latestSuccessful('loa')?->id)->toBe($run->id);
});

it('still makes a run partial when rows errored, whatever needs review', function () {
    $repo = app(SyncRunRepository::class);
    $run = $repo->open('loa', 'system');

    $repo->complete($run, inserted: 0, updated: 0, inactivated: 0, errored: 2, error: 'Thread 4002: HTTP 503', needsReview: 1);

    expect($run->fresh()->status)->toBe(SyncRunStatus::Partial)->and($repo->latestSuccessful('loa'))->toBeNull();
});

it('counts none for a run that does not say, as the roster sync does', function () {
    $repo = app(SyncRunRepository::class);
    $run = $repo->open('roster', 'system');

    $repo->complete($run, inserted: 1, updated: 0, inactivated: 0, errored: 0);

    expect($run->fresh()->needs_review)->toBe(0);
});
