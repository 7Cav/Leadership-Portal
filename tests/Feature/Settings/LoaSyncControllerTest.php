<?php

use App\Jobs\SyncLoasJob;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Illuminate\Support\Facades\Queue;

// Story 4.2: the manual leave-sync trigger, mirroring the roster one.

it('lets an Admin dispatch a manual leave sync, stamping their user id as triggeredBy', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/settings/loa-sync')->assertRedirect();

    Queue::assertPushed(SyncLoasJob::class, fn (SyncLoasJob $job) => $job->triggeredBy === $admin->id && $job->full === false);
});

it('lets an Admin ask for a full run', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/settings/loa-sync', ['full' => '1'])->assertRedirect();

    Queue::assertPushed(SyncLoasJob::class, fn (SyncLoasJob $job) => $job->full === true);
});

it('refuses a non-Admin with 403 and dispatches nothing (AD-6)', function () {
    Queue::fake();

    $this->actingAs(User::factory()->create())->post('/settings/loa-sync')->assertForbidden();

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
