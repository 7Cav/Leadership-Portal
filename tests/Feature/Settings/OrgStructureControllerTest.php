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

it('lets an Admin rename a unit (AC #1)', function () {
    $admin = User::factory()->admin()->create();
    $regiment = app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);

    $this->actingAs($admin)->patch("/settings/org-structure/{$regiment->id}", [
        'name' => '7th Cav Regiment',
        'callsign' => '7CAV',
        'parent_id' => null,
    ])->assertRedirect();

    expect(Unit::find($regiment->id)->name)->toBe('7th Cav Regiment')
        ->and(Unit::find($regiment->id)->callsign)->toBe('7CAV');
});

it('lets an Admin re-parent a unit, recomputing the subtree path (AC #2)', function () {
    $admin = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalionA = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $battalionB = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalionA->id);

    $this->actingAs($admin)->patch("/settings/org-structure/{$company->id}", [
        'name' => $company->name,
        'callsign' => $company->callsign,
        'parent_id' => $battalionB->id,
    ])->assertRedirect();

    $moved = Unit::find($company->id);
    expect($moved->parent_id)->toBe($battalionB->id)
        ->and($moved->path)->toBe("{$battalionB->path}{$company->id}/");
});

it('never persists the rename half of a request whose re-parent is refused', function () {
    // A rejected re-parent must not leave the accompanying rename committed behind the 422 —
    // that would look to the Admin like the whole request was refused when half of it landed.
    $admin = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);

    $this->actingAs($admin)->patch("/settings/org-structure/{$battalion->id}", [
        'name' => 'Hijacked Battalion Name',
        'callsign' => $battalion->callsign,
        'parent_id' => $company->id, // cyclic: battalion under its own descendant
    ])->assertInvalid('parent_id');

    expect(Unit::find($battalion->id)->name)->toBe('1st Battalion');
});

it('422s a cyclic re-parent as a form error on parent_id (AC #3)', function () {
    $admin = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);

    $this->actingAs($admin)->patch("/settings/org-structure/{$battalion->id}", [
        'name' => $battalion->name,
        'callsign' => $battalion->callsign,
        'parent_id' => $company->id,
    ])->assertInvalid('parent_id');

    expect(Unit::find($battalion->id)->parent_id)->toBe($regiment->id);
});

it('refuses a non-Admin server-side on rename/re-parent, even with the UI control hidden (AC #5)', function () {
    $user = User::factory()->create();
    $regiment = app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);

    $this->actingAs($user)->patch("/settings/org-structure/{$regiment->id}", [
        'name' => 'Hijacked Name',
        'callsign' => null,
        'parent_id' => null,
    ])->assertForbidden();

    expect(Unit::find($regiment->id)->name)->toBe('7th Cavalry Regiment');
});

it('422s a missing name on update', function () {
    $admin = User::factory()->admin()->create();
    $regiment = app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);

    $this->actingAs($admin)->patch("/settings/org-structure/{$regiment->id}", [
        'callsign' => null,
        'parent_id' => null,
    ])->assertInvalid('name');

    expect(Unit::find($regiment->id)->name)->toBe('7th Cavalry Regiment');
});

it('lets an Admin delete a childless unit (AC #4)', function () {
    $admin = User::factory()->admin()->create();
    $unit = app(UnitRepository::class)->create(UnitType::Section, 'Nightriders', '1/1/E/2-7', null);

    $this->actingAs($admin)->delete("/settings/org-structure/{$unit->id}")->assertRedirect();

    expect(Unit::find($unit->id))->toBeNull();
});

it('422s a delete blocked by remaining children, with a clear reason (AC #4)', function () {
    $admin = User::factory()->admin()->create();
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);

    $this->actingAs($admin)->delete("/settings/org-structure/{$regiment->id}")->assertInvalid('delete');

    expect(Unit::find($regiment->id))->not->toBeNull();
});

it('refuses a non-Admin delete server-side, even with the UI control hidden (AC #5)', function () {
    $user = User::factory()->create();
    $unit = app(UnitRepository::class)->create(UnitType::Section, 'Nightriders', '1/1/E/2-7', null);

    $this->actingAs($user)->delete("/settings/org-structure/{$unit->id}")->assertForbidden();

    expect(Unit::find($unit->id))->not->toBeNull();
});
