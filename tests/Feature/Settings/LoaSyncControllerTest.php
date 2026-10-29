<?php

use App\Jobs\SyncLoasJob;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

// Story 4.2: the manual leave-sync trigger, mirroring the roster one.

it('lets an Admin dispatch a manual leave sync, stamping their user id as triggeredBy', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/settings/loa-sync')
        ->assertRedirect()
        ->assertSessionHas('notice', 'Leave sync queued. Reload in a few minutes to see the result.');

    Queue::assertPushed(SyncLoasJob::class, fn (SyncLoasJob $job) => $job->triggeredBy === $admin->id && $job->full === false && $job->followUp === false);
});

describe('while a sync of the same kind is already queued or running', function () {
    // The fake never runs a job, so the first one stays pending and keeps holding its unique lock, as a running
    // sync does.
    it('queues the request as the one follow-up run, after the running one, and says so', function () {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/settings/loa-sync');

        $this->post('/settings/loa-sync')
            ->assertRedirect()
            ->assertSessionHas('notice', 'A leave sync is already queued or running. Yours will run right after it.');

        Queue::assertPushed(SyncLoasJob::class, 2);
        Queue::assertPushed(SyncLoasJob::class, fn (SyncLoasJob $job) => $job->followUp && $job->triggeredBy === $admin->id && ! $job->full);
    });

    it('adds nothing more once the follow-up is queued too, and says that one will include the request', function () {
        Queue::fake();
        $this->actingAs(User::factory()->admin()->create());
        $this->post('/settings/loa-sync');
        $this->post('/settings/loa-sync');

        $this->post('/settings/loa-sync')
            ->assertRedirect()
            ->assertSessionHas('notice', 'A leave sync is running and another is queued after it. That one will include your request.');

        Queue::assertPushed(SyncLoasJob::class, 2);
    });

    it('keeps the full and the incremental kinds apart', function () {
        Queue::fake();
        $this->actingAs(User::factory()->admin()->create());
        $this->post('/settings/loa-sync');

        $this->post('/settings/loa-sync', ['full' => '1'])->assertSessionHas('notice', 'Leave sync queued. Reload in a few minutes to see the result.');

        Queue::assertPushed(SyncLoasJob::class, fn (SyncLoasJob $job) => $job->full && ! $job->followUp);
    });

    it('queues the next one normally once the running sync has finished and released its lock', function () {
        Queue::fake();
        $this->actingAs(User::factory()->admin()->create());
        $this->post('/settings/loa-sync');
        // What the worker does when a unique job finishes: drop its lock.
        Cache::lock(UniqueLock::getKey(new SyncLoasJob(1)))->forceRelease();

        $this->post('/settings/loa-sync')->assertSessionHas('notice', 'Leave sync queued. Reload in a few minutes to see the result.');

        Queue::assertNotPushed(SyncLoasJob::class, fn (SyncLoasJob $job) => $job->followUp);
    });
});

it('shows the notice to the page it returns to, once', function () {
    Queue::fake();
    $this->actingAs(User::factory()->admin()->create());

    $this->from('/home')->post('/settings/loa-sync');

    $this->get('/home')->assertInertia(fn ($page) => $page->where('flash.notice', 'Leave sync queued. Reload in a few minutes to see the result.'));
    $this->get('/home')->assertInertia(fn ($page) => $page->where('flash.notice', null));
});

it('lets an Admin ask for a full run', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/settings/loa-sync', ['full' => '1'])->assertRedirect();

    Queue::assertPushed(SyncLoasJob::class, fn (SyncLoasJob $job) => $job->full === true);
});

it('throttles the manual trigger: the 6th request from one Admin in a minute gets 429', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    for ($i = 1; $i <= 5; $i++) {
        $this->post('/settings/loa-sync')->assertRedirect();
    }

    $this->post('/settings/loa-sync')->assertStatus(429);
});

it('refuses a non-Admin with 403 and dispatches nothing (AD-6)', function () {
    Queue::fake();

    $this->actingAs(User::factory()->serving()->create())->post('/settings/loa-sync')->assertForbidden();

    Queue::assertNothingPushed();
});

it('requires authentication', function () {
    Queue::fake();

    $this->post('/settings/loa-sync')->assertRedirect(route('login'));

    Queue::assertNothingPushed();
});

it('is refused while an Admin is previewing a leader (read-only preview, 1.13)', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $unit = app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);

    $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $unit->id]);

    $this->post('/settings/loa-sync')->assertForbidden();

    Queue::assertNothingPushed();
});
