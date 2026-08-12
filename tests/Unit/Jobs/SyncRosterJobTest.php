<?php

use App\Jobs\SyncRosterJob;
use App\Sync\SyncRosterHandler;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Story 2.4 — the job is a thin wrapper: it exists only to move `SyncRosterHandler` off the
 * request path (AD-23) and to carry the overlap lock both dispatch paths share.
 */
it('delegates straight to SyncRosterHandler with the given triggeredBy', function () {
    $handler = Mockery::mock(SyncRosterHandler::class);
    $handler->shouldReceive('handle')->once()->with(42);

    (new SyncRosterJob(42))->handle($handler);
});

it('defaults triggeredBy to system for the scheduler path', function () {
    $handler = Mockery::mock(SyncRosterHandler::class);
    $handler->shouldReceive('handle')->once()->with('system');

    (new SyncRosterJob)->handle($handler);
});

it('carries a WithoutOverlapping lock keyed on the roster source', function () {
    $middleware = (new SyncRosterJob)->middleware();

    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
    expect($middleware[0]->key)->toBe('roster');
    // A manual trigger fired mid-run must defer rather than vanish (AC: "skipped or
    // deferred, never overlapping") — a null releaseAfter would silently drop it instead.
    expect($middleware[0]->releaseAfter)->not->toBeNull();
});
