<?php

use App\Models\AuditLogEntry;
use App\Models\User;

it('requires authentication to view the audit log', function () {
    $this->get('/settings/audit-log')->assertRedirect(route('login'));
});

it('refuses a non-Admin server-side with 403 (AD-6) — the whole screen is Admin-only', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/audit-log')->assertForbidden();
});

it('renders the audit log for an Admin', function () {
    $admin = User::factory()->admin()->create();
    $actor = User::factory()->create(['username' => 'jgryspeert']);
    AuditLogEntry::factory()->create([
        'actor_id' => $actor->id,
        'action' => 'unit.created',
        'target_type' => 'Unit',
        'target_id' => '1',
        'reason' => 'Reorganizing the chain of command',
    ]);

    $this->actingAs($admin)->get('/settings/audit-log')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/AuditLog')
            ->has('entries.data', 1)
            ->where('entries.data.0.actor', 'jgryspeert')
            ->where('entries.data.0.action', 'unit.created')
            ->where('entries.data.0.target_type', 'Unit')
            ->where('entries.data.0.target_id', '1')
            ->where('entries.data.0.reason', 'Reorganizing the chain of command')
            ->has('entries.data.0.content_digest')
            ->has('entries.data.0.created_at'));
});

it('narrows the result set by actor filter (AC #2)', function () {
    $admin = User::factory()->admin()->create();
    $wanted = User::factory()->create();
    $other = User::factory()->create();
    AuditLogEntry::factory()->create(['actor_id' => $wanted->id]);
    AuditLogEntry::factory()->create(['actor_id' => $other->id]);

    $this->actingAs($admin)->get('/settings/audit-log?actor_id='.$wanted->id)
        ->assertInertia(fn ($page) => $page->has('entries.data', 1));
});

it('narrows the result set by action filter (AC #2)', function () {
    $admin = User::factory()->admin()->create();
    $actor = User::factory()->create();
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'action' => 'unit.created']);
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'action' => 'unit.deleted']);

    $this->actingAs($admin)->get('/settings/audit-log?action=unit.deleted')
        ->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.action', 'unit.deleted'));
});

it('combines actor and action filters with AND (AC #2)', function () {
    $admin = User::factory()->admin()->create();
    $actor = User::factory()->create();
    $other = User::factory()->create();
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'action' => 'unit.created']);
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'action' => 'unit.deleted']);
    AuditLogEntry::factory()->create(['actor_id' => $other->id, 'action' => 'unit.created']);

    $this->actingAs($admin)->get('/settings/audit-log?actor_id='.$actor->id.'&action=unit.created')
        ->assertInertia(fn ($page) => $page->has('entries.data', 1));
});

it('narrows the result set by a date range (AC #2)', function () {
    $admin = User::factory()->admin()->create();
    $actor = User::factory()->create();
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'created_at' => '2026-05-01 12:00:00']);
    $inRange = AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'created_at' => '2026-05-10 12:00:00']);
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'created_at' => '2026-05-20 12:00:00']);

    $this->actingAs($admin)->get('/settings/audit-log?date_from=2026-05-05&date_to=2026-05-15')
        ->assertInertia(fn ($page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.id', $inRange->id));
});

it('rejects an invalid date filter with a 422 rather than a raw DB error', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/settings/audit-log?date_from=not-a-date')
        ->assertInvalid('date_from');
});

it('passes distinct actor and action options for the filter controls', function () {
    $admin = User::factory()->admin()->create();
    $actor = User::factory()->create(['username' => 'zed']);
    AuditLogEntry::factory()->create(['actor_id' => $actor->id, 'action' => 'unit.created']);

    $this->actingAs($admin)->get('/settings/audit-log')
        ->assertInertia(fn ($page) => $page
            ->where('actorOptions.0.username', 'zed')
            ->where('actionOptions.0', 'unit.created'));
});

it('creates no audit_log entries of its own (AC #6, read-only)', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/settings/audit-log');

    expect(AuditLogEntry::count())->toBe(0);
});
