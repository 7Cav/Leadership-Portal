<?php

use App\Jobs\SyncRosterJob;
use App\Models\User;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

it('lets an Admin dispatch a manual roster sync, stamping their user id as triggeredBy', function () {
    Queue::fake();

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/settings/roster-sync')->assertRedirect();

    Queue::assertPushed(SyncRosterJob::class, fn (SyncRosterJob $job) => $job->triggeredBy === $admin->id);
});

it('queues one sync however often an Admin clicks while it is still pending', function () {
    Queue::fake();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    // The fake never runs the first job, so it stays pending and keeps holding the unique lock,
    // exactly as a sync waiting on a busy worker would.
    $this->post('/settings/roster-sync')->assertRedirect();
    $this->post('/settings/roster-sync')->assertRedirect();
    $this->post('/settings/roster-sync')->assertRedirect();

    Queue::assertPushed(SyncRosterJob::class, 1);
});

it('dedupes a scheduled dispatch against a pending manual one: one roster, one uniqueId', function () {
    Queue::fake();

    SyncRosterJob::dispatch(1);
    SyncRosterJob::dispatch('system');

    Queue::assertPushed(SyncRosterJob::class, 1);
    expect((new SyncRosterJob(1))->uniqueId())->toBe((new SyncRosterJob)->uniqueId());
});

it('queues the next sync once the pending one has run and released its lock', function () {
    Queue::fake();

    SyncRosterJob::dispatch(1);
    // What the worker does when a unique job finishes: drop its lock.
    Cache::lock(UniqueLock::getKey(new SyncRosterJob(1)))->forceRelease();
    SyncRosterJob::dispatch(1);

    Queue::assertPushed(SyncRosterJob::class, 2);
});

it('throttles the manual trigger: the 6th request from one Admin in a minute gets 429', function () {
    Queue::fake();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    for ($i = 1; $i <= 5; $i++) {
        $this->post('/settings/roster-sync')->assertRedirect();
    }

    $this->post('/settings/roster-sync')->assertStatus(429);
});

it('refuses a non-Admin manual trigger with 403 (AC #4, AD-6)', function () {
    Queue::fake();

    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/roster-sync')->assertForbidden();

    Queue::assertNothingPushed();
});

it('requires authentication to trigger a manual sync', function () {
    Queue::fake();

    $this->post('/settings/roster-sync')->assertRedirect(route('login'));

    Queue::assertNothingPushed();
});
