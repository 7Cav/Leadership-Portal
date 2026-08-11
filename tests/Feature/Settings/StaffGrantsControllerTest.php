<?php

use App\Authorization\ScopeResolver;
use App\Models\PortalRoleGrant;
use App\Models\Unit;
use App\Models\User;

it('renders the grants list for any authenticated user', function () {
    $unit = Unit::factory()->create();
    $target = User::factory()->create();
    PortalRoleGrant::factory()->create(['user_id' => $target->id, 'unit_id' => $unit->id]);
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/staff-grants')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/StaffGrants')
            ->has('grants', 1)
            ->where('isAdmin', false));
});

it('excludes Admins from the target-user picker (AD-7 — a grant to an Admin is meaningless)', function () {
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->create();

    $this->actingAs($admin)->get('/settings/staff-grants')
        ->assertInertia(fn ($page) => $page
            ->where('users', fn ($users) => collect($users)->pluck('id')->all() === [$staff->id]));
});

it('lets an Admin grant a non-admin staff user scope at a chosen node (AC #1)', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();

    $this->actingAs($admin)->post('/settings/staff-grants', [
        'user_id' => $target->id,
        'unit_id' => $unit->id,
        'reason' => 'S1 staff, no line billet',
    ])->assertRedirect();

    $grant = PortalRoleGrant::where('user_id', $target->id)->where('unit_id', $unit->id)->first();
    expect($grant)->not->toBeNull();
});

it('refuses a non-Admin grant server-side with 403 (AC #4, AD-6)', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();

    $this->actingAs($user)->post('/settings/staff-grants', [
        'user_id' => $target->id,
        'unit_id' => $unit->id,
        'reason' => 'S1 staff, no line billet',
    ])->assertForbidden();

    expect(PortalRoleGrant::count())->toBe(0);
});

it('requires authentication to view or author grants', function () {
    $this->get('/settings/staff-grants')->assertRedirect(route('login'));

    $this->post('/settings/staff-grants', [
        'user_id' => 1,
        'unit_id' => 1,
        'reason' => 'reason',
    ])->assertRedirect(route('login'));
});

it('422s a missing user_id', function () {
    $admin = User::factory()->admin()->create();
    $unit = Unit::factory()->create();

    $this->actingAs($admin)->post('/settings/staff-grants', [
        'unit_id' => $unit->id,
        'reason' => 'reason',
    ])->assertInvalid('user_id');

    expect(PortalRoleGrant::count())->toBe(0);
});

it('422s a missing unit_id', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    $this->actingAs($admin)->post('/settings/staff-grants', [
        'user_id' => $target->id,
        'reason' => 'reason',
    ])->assertInvalid('unit_id');

    expect(PortalRoleGrant::count())->toBe(0);
});

it('422s a missing reason (AD-16)', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();

    $this->actingAs($admin)->post('/settings/staff-grants', [
        'user_id' => $target->id,
        'unit_id' => $unit->id,
    ])->assertInvalid('reason');

    expect(PortalRoleGrant::count())->toBe(0);
});

it('422s a whitespace-only reason — Laravel\'s `required` rule already trims (AD-16)', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();

    $this->actingAs($admin)->post('/settings/staff-grants', [
        'user_id' => $target->id,
        'unit_id' => $unit->id,
        'reason' => '   ',
    ])->assertInvalid('reason');

    expect(PortalRoleGrant::count())->toBe(0);
});

it('422s a grant targeting a user who already holds the admin flag (AD-7)', function () {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();
    $unit = Unit::factory()->create();

    $this->actingAs($admin)->post('/settings/staff-grants', [
        'user_id' => $otherAdmin->id,
        'unit_id' => $unit->id,
        'reason' => 'reason',
    ])->assertInvalid('user_id');

    expect(PortalRoleGrant::count())->toBe(0);
});

it('lets an Admin revoke a grant (AC #2)', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();
    $grant = PortalRoleGrant::factory()->create(['user_id' => $target->id, 'unit_id' => $unit->id]);

    $this->actingAs($admin)->delete("/settings/staff-grants/{$grant->id}", [
        'reason' => 'no longer staff',
    ])->assertRedirect();

    expect(PortalRoleGrant::find($grant->id))->toBeNull();
});

it('refuses a non-Admin revoke server-side with 403 (AD-6)', function () {
    $user = User::factory()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();
    $grant = PortalRoleGrant::factory()->create(['user_id' => $target->id, 'unit_id' => $unit->id]);

    $this->actingAs($user)->delete("/settings/staff-grants/{$grant->id}", [
        'reason' => 'no longer staff',
    ])->assertForbidden();

    expect(PortalRoleGrant::find($grant->id))->not->toBeNull();
});

it('422s a revoke with no reason (AD-16)', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();
    $grant = PortalRoleGrant::factory()->create(['user_id' => $target->id, 'unit_id' => $unit->id]);

    $this->actingAs($admin)->delete("/settings/staff-grants/{$grant->id}", [])
        ->assertInvalid('reason');

    expect(PortalRoleGrant::find($grant->id))->not->toBeNull();
});

it('reflects a revoke in the target\'s effective scope on the very next request (AC #2)', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();
    $grant = PortalRoleGrant::factory()->create(['user_id' => $target->id, 'unit_id' => $unit->id]);

    expect(app(ScopeResolver::class)->resolve($target))->toContain($unit->id);

    $this->actingAs($admin)->delete("/settings/staff-grants/{$grant->id}", [
        'reason' => 'no longer staff',
    ])->assertRedirect();

    expect(app(ScopeResolver::class)->resolve($target->fresh()))->not->toContain($unit->id);
});
