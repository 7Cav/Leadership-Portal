<?php

use App\Authorization\ScopeResolver;
use App\Kpi\Headcount;
use App\Milpac\TrooperMilpac;
use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;

// Deactivating a unit on Org structure: an Admin hides a unit that no longer exists in the real org, such as the
// Recruitment Oversight Office, with everything under it. Nothing is deleted, and Reactivate brings it back.

function duPlace(Unit $unit, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create(['status' => TrooperStatus::Combat, ...$attributes]);
    $trooper->forceFill(['primary_section_id' => $unit->id])->save();

    return $trooper;
}

/** @return array<int, array<string, mixed>> the page's units by id */
function duUnits($response): array
{
    return collect($response->viewData('page')['props']['units'])->keyBy('id')->all();
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->s1 = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->s2 = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->platoon->id);
    $this->cos = $units->create(UnitType::Group, 'Chief of Staff', 'COS', $this->regiment->id, UnitKind::Staff);
    $this->roo = $units->create(UnitType::Group, 'Recruitment Oversight Office', 'ROO', $this->cos->id, UnitKind::Staff);
    $this->rooDesk = $units->create(UnitType::Group, 'ROO Desk', 'ROOD', $this->roo->id, UnitKind::Staff);

    duPlace($this->s1);
    duPlace($this->s2);
    duPlace($this->s2);
    duPlace($this->cos);
    $this->rooOfficer = duPlace($this->roo, ['username' => 'Hale.R', 'billet' => 'ROO Officer']);
    duPlace($this->rooDesk, ['username' => 'Ives.D']);

    $this->admin = User::factory()->admin()->create();
});

it('lets an Admin deactivate a unit with an optional reason, recorded in the audit log', function () {
    $this->actingAs($this->admin)
        ->patch("/settings/org-structure/{$this->roo->id}/active", ['active' => false, 'reason' => 'The office no longer exists'])
        ->assertRedirect('/settings/org-structure');

    $roo = $this->roo->fresh();
    $entry = AuditLogEntry::where('action', 'unit.deactivated')->sole();

    expect($roo->deactivated_at)->not->toBeNull()
        ->and($roo->deactivated_by)->toBe($this->admin->id)
        ->and($entry->reason)->toBe('The office no longer exists')
        ->and($entry->target_type)->toBe('Unit')
        ->and($entry->target_id)->toBe((string) $this->roo->id);

    // No reason is fine too.
    $this->patch("/settings/org-structure/{$this->s2->id}/active", ['active' => false])->assertRedirect('/settings/org-structure');
    expect($this->s2->fresh()->deactivated_at)->not->toBeNull();
});

it('refuses anyone but an Admin', function () {
    $this->actingAs(User::factory()->serving()->create())
        ->patch("/settings/org-structure/{$this->roo->id}/active", ['active' => false])
        ->assertForbidden();

    expect($this->roo->fresh()->deactivated_at)->toBeNull();
});

it('refuses the regiment, a unit already inactive, and reactivating one that is only inactive through its parent', function () {
    $this->actingAs($this->admin);

    $this->patch("/settings/org-structure/{$this->regiment->id}/active", ['active' => false])->assertSessionHasErrors(['active' => 'The regiment cannot be deactivated.']);
    $this->patch("/settings/org-structure/{$this->roo->id}/active", ['active' => false]);
    $this->patch("/settings/org-structure/{$this->roo->id}/active", ['active' => false])->assertSessionHasErrors(['active' => 'This unit is already inactive.']);
    $this->patch("/settings/org-structure/{$this->rooDesk->id}/active", ['active' => false])->assertSessionHasErrors('active');
    $this->patch("/settings/org-structure/{$this->rooDesk->id}/active", ['active' => true])->assertSessionHasErrors('active');

    expect(AuditLogEntry::where('action', 'unit.deactivated')->count())->toBe(1);
});

it('leaves an inactive unit and its subtree out of every list of units, and brings them back on reactivate', function () {
    $repo = app(UnitRepository::class);
    $this->actingAs($this->admin)->patch("/settings/org-structure/{$this->roo->id}/active", ['active' => false]);
    $hidden = [$this->roo->id, $this->rooDesk->id];

    expect($repo->tree()->modelKeys())->not->toContain(...$hidden)
        ->and($repo->allIds()->all())->not->toContain(...$hidden)
        ->and($repo->subtreeIds($this->cos)->all())->toBe([$this->cos->id])
        ->and($repo->subtreeIds($this->roo)->all())->toBe([])
        ->and($repo->children($this->cos)->modelKeys())->toBe([])
        ->and($repo->treeWithInactive()->modelKeys())->toContain(...$hidden)
        ->and($repo->inactiveIds()->all())->toBe($hidden)
        ->and($repo->findActive($this->rooDesk->id))->toBeNull()
        ->and($repo->find($this->rooDesk->id))->not->toBeNull()
        ->and(app(ScopeResolver::class)->resolve($this->admin)->all())->not->toContain(...$hidden)
        ->and(app(Headcount::class)->forUnit($this->cos))->toBe(1);

    $this->patch("/settings/org-structure/{$this->roo->id}/active", ['active' => true, 'reason' => 'Back again'])->assertRedirect('/settings/org-structure');

    expect($repo->tree()->modelKeys())->toContain(...$hidden)
        ->and($repo->inactiveIds()->all())->toBe([])
        ->and($this->roo->fresh()->deactivated_by)->toBeNull()
        ->and(AuditLogEntry::where('action', 'unit.reactivated')->sole()->reason)->toBe('Back again')
        ->and(app(Headcount::class)->forUnit($this->cos))->toBe(3);
});

it('drops an inactive section from its platoon: the report chain, the roll-up and the headcount', function () {
    $this->actingAs($this->admin)->patch("/settings/org-structure/{$this->s2->id}/active", ['active' => false]);
    $repo = app(UnitRepository::class);

    expect($repo->lineChildren($this->platoon)->modelKeys())->toBe([$this->s1->id])
        ->and($repo->lineSubtree($this->company)->modelKeys())->toBe([$this->company->id, $this->platoon->id, $this->s1->id])
        ->and($repo->sectionsAmong([$this->s1->id, $this->s2->id])->modelKeys())->toBe([$this->s1->id])
        ->and(app(Headcount::class)->forUnit($this->platoon))->toBe(1)
        ->and(app(TrooperRepository::class)->placedWithin($this->platoon))->toHaveCount(1)
        ->and(Trooper::query()->assignedWithin($this->platoon)->count())->toBe(1);
});

it('shows inactive units on Org structure, flagged, with who is still placed there, counted nowhere', function () {
    $this->actingAs($this->admin)->patch("/settings/org-structure/{$this->roo->id}/active", ['active' => false]);

    $response = $this->get('/settings/org-structure')->assertOk();
    $units = duUnits($response);
    $placed = collect($response->viewData('page')['props']['inactivePlaced']);

    expect($units[$this->roo->id]['inactive'])->toBeTrue()
        ->and($units[$this->roo->id]['deactivated_at'])->not->toBeNull()
        ->and($units[$this->rooDesk->id]['inactive'])->toBeTrue()
        ->and($units[$this->rooDesk->id]['deactivated_at'])->toBeNull()
        ->and($units[$this->cos->id]['inactive'])->toBeFalse()
        ->and($units[$this->roo->id]['placed_count'])->toBe(2)
        ->and($units[$this->roo->id]['trooper_count'])->toBe(0)
        ->and($units[$this->cos->id]['trooper_count'])->toBe(1)
        ->and($units[$this->regiment->id]['trooper_count'])->toBe(4)
        ->and($placed->pluck('displayName')->all())->toHaveCount(2)
        ->and($placed->firstWhere('id', $this->rooOfficer->id)['unitId'])->toBe($this->roo->id);

    // Only an Admin gets the list.
    $this->actingAs(User::factory()->serving()->create());
    expect($this->get('/settings/org-structure')->viewData('page')['props']['inactivePlaced'])->toBeNull();
});

it('sends a non-Admin no inactive unit and no inactive data on Org structure', function () {
    $this->actingAs($this->admin)->patch("/settings/org-structure/{$this->roo->id}/active", ['active' => false]);

    $props = $this->actingAs(User::factory()->serving()->create())->get('/settings/org-structure')->assertOk()->viewData('page')['props'];
    $units = collect($props['units']);

    expect($units->pluck('id')->all())->not->toContain($this->roo->id, $this->rooDesk->id)
        ->and($units->pluck('id')->all())->toContain($this->cos->id)
        ->and($units->contains(fn (array $unit) => array_key_exists('inactive', $unit) || array_key_exists('deactivated_at', $unit) || array_key_exists('placed_count', $unit)))->toBeFalse()
        ->and(collect($props['references'])->pluck('unit_id')->all())->not->toContain($this->roo->id)
        ->and($props['inactivePlaced'])->toBeNull();
});

it('keeps a staff grant on an inactive unit listed for an Admin, tagged, giving no scope until the unit is back', function () {
    $holder = User::factory()->serving()->create(['username' => 'Grant.H']);
    $grant = PortalRoleGrant::factory()->create(['user_id' => $holder->id, 'unit_id' => $this->rooDesk->id]);
    $this->actingAs($this->admin)->patch("/settings/org-structure/{$this->roo->id}/active", ['active' => false]);

    $rows = collect($this->get('/settings/staff-grants')->assertOk()->viewData('page')['props']['grants']);
    expect($rows->firstWhere('id', $grant->id))->toMatchArray(['unit_inactive' => true, 'unit_label' => 'Chief of Staff › Recruitment Oversight Office › ROO Desk'])
        ->and(app(ScopeResolver::class)->resolve($holder))->not->toContain($this->rooDesk->id);

    // Anyone else sees no inactive unit, so not its grant either.
    $others = collect($this->actingAs(User::factory()->serving()->create())->get('/settings/staff-grants')->viewData('page')['props']['grants']);
    expect($others->pluck('id')->all())->not->toContain($grant->id);

    $this->actingAs($this->admin)->patch("/settings/org-structure/{$this->roo->id}/active", ['active' => true]);
    $rows = collect($this->get('/settings/staff-grants')->viewData('page')['props']['grants']);
    expect($rows->firstWhere('id', $grant->id)['unit_inactive'])->toBeFalse()
        ->and(app(ScopeResolver::class)->resolve($holder))->toContain($this->rooDesk->id);
});

it('lets an Admin revoke a staff grant on an inactive unit', function () {
    $grant = PortalRoleGrant::factory()->create(['user_id' => User::factory()->create()->id, 'unit_id' => $this->roo->id]);
    $this->actingAs($this->admin)->patch("/settings/org-structure/{$this->roo->id}/active", ['active' => false]);

    $this->delete("/settings/staff-grants/{$grant->id}", ['reason' => 'Office closed'])->assertRedirect();

    expect(PortalRoleGrant::find($grant->id))->toBeNull();
});

it('counts the people in a unit being deactivated, holding groups included', function () {
    $units = duUnits($this->actingAs($this->admin)->get('/settings/org-structure'));

    expect($units[$this->roo->id]['placed_count'])->toBe(2)
        ->and($units[$this->platoon->id]['placed_count'])->toBe(3);
});

it('reads someone placed in an inactive unit as unplaced on their milpac page', function () {
    $this->actingAs($this->admin)->patch("/settings/org-structure/{$this->roo->id}/active", ['active' => false]);

    $page = app(TrooperMilpac::class)->for($this->rooOfficer->fresh(), app(ScopeResolver::class)->resolve($this->admin));

    expect($page['trooper']['section'])->toBeNull();
});

it('refuses adding or moving a unit under an inactive one', function () {
    $this->actingAs($this->admin)->patch("/settings/org-structure/{$this->roo->id}/active", ['active' => false]);

    $this->post('/settings/org-structure', ['type' => UnitType::Group->value, 'parent_id' => $this->roo->id, 'name' => 'Desk Two'])
        ->assertSessionHasErrors(['parent_id' => 'That parent unit is inactive. Reactivate it first or choose another.']);
    $this->patch("/settings/org-structure/{$this->platoon->id}", ['name' => '1st Platoon', 'callsign' => '1/A/1-7', 'parent_id' => $this->roo->id])
        ->assertSessionHasErrors('parent_id');

    expect(Unit::query()->where('name', 'Desk Two')->exists())->toBeFalse()
        ->and($this->platoon->fresh()->parent_id)->toBe($this->company->id);
});
