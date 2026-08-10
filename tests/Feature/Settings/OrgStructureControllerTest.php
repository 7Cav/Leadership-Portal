<?php

use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

it('renders the org tree for any authenticated user', function () {
    app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/org-structure')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/OrgStructure')
            ->has('units', 1)
            ->where('isAdmin', false));
});

it('lets an Admin create a node under a chosen parent (AC #2)', function () {
    $admin = User::factory()->admin()->create();
    $regiment = app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);

    $this->actingAs($admin)->post('/settings/org-structure', [
        'type' => 'battalion',
        'name' => '2nd Battalion',
        'callsign' => '2-7',
        'parent_id' => $regiment->id,
    ])->assertRedirect();

    $created = Unit::where('name', '2nd Battalion')->first();
    expect($created)->not->toBeNull()
        ->and($created->parent_id)->toBe($regiment->id)
        ->and($created->path)->toBe("{$regiment->path}{$created->id}/");
});

it('refuses a non-Admin server-side, even though the UI hides the control (AC #5, AD-6)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/org-structure', [
        'type' => 'battalion',
        'name' => '2nd Battalion',
        'callsign' => '2-7',
        'parent_id' => null,
    ])->assertForbidden();

    expect(Unit::count())->toBe(0);
});

it('requires authentication to view or author the tree', function () {
    $this->get('/settings/org-structure')->assertRedirect(route('login'));

    $this->post('/settings/org-structure', [
        'type' => 'regiment',
        'name' => '7th Cavalry Regiment',
        'callsign' => null,
        'parent_id' => null,
    ])->assertRedirect(route('login'));
});

it('422s an unrecognized type value', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/settings/org-structure', [
        'type' => 'squad', // not a UnitType member
        'name' => 'Bogus',
        'callsign' => null,
        'parent_id' => null,
    ])->assertInvalid('type');

    expect(Unit::count())->toBe(0);
});

it('422s a missing name', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/settings/org-structure', [
        'type' => 'regiment',
        'callsign' => null,
        'parent_id' => null,
    ])->assertInvalid('name');

    expect(Unit::count())->toBe(0);
});

it('422s a parent_id that does not exist', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/settings/org-structure', [
        'type' => 'battalion',
        'name' => '2nd Battalion',
        'callsign' => '2-7',
        'parent_id' => 999999,
    ])->assertInvalid('parent_id');

    expect(Unit::count())->toBe(0);
});

it('422s a node placed at the wrong echelon rank, as a form error on type (AC #6 counterpart)', function () {
    $admin = User::factory()->admin()->create();
    $regiment = app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);

    // Skips battalion — a company cannot be placed directly under a regiment.
    $this->actingAs($admin)->post('/settings/org-structure', [
        'type' => 'company',
        'name' => 'Echo Company',
        'callsign' => 'E/2-7',
        'parent_id' => $regiment->id,
    ])->assertInvalid('type');

    expect(Unit::where('name', 'Echo Company')->exists())->toBeFalse();
});

it('422s a non-Regiment root node', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/settings/org-structure', [
        'type' => 'battalion',
        'name' => '2nd Battalion',
        'callsign' => '2-7',
        'parent_id' => null,
    ])->assertInvalid('type');

    expect(Unit::count())->toBe(0);
});
