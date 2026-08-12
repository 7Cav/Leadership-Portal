<?php

use App\Jobs\SyncRosterJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('lets an Admin dispatch a manual roster sync, stamping their user id as triggeredBy', function () {
    Queue::fake();

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/settings/roster-sync')->assertRedirect();

    Queue::assertPushed(SyncRosterJob::class, fn (SyncRosterJob $job) => $job->triggeredBy === $admin->id);
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
