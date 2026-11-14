<?php

use App\Models\AuditLogEntry;
use App\Models\Unit;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

// Add a staff group from Org structure (2026-11-13): the same `store` and `CreateUnit` path a line unit takes, with a
// name in place of an ordinal.

it('lets an Admin add a staff group under the regiment or under a portfolio, audited', function () {
    $units = app(UnitRepository::class);
    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $portfolio = $units->create(UnitType::Group, 'Executive Officer', 'XO', $regiment->id, UnitKind::Staff);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/settings/org-structure', ['type' => 'group', 'parent_id' => $portfolio->id, 'name' => 'Member Advocate'])
        ->assertRedirect(route('settings.org-structure'));
    $this->actingAs($admin)->post('/settings/org-structure', ['type' => 'group', 'parent_id' => $regiment->id, 'name' => 'Discord'])
        ->assertRedirect(route('settings.org-structure'));

    $advocate = Unit::query()->where('name', 'Member Advocate')->sole();
    expect([$advocate->parent_id, $advocate->kind, $advocate->callsign])->toBe([$portfolio->id, UnitKind::Staff, null])
        ->and(Unit::query()->where('name', 'Discord')->sole()->parent_id)->toBe($regiment->id)
        ->and(AuditLogEntry::query()->where('action', 'unit.created')->count())->toBe(2);
});

it('refuses a staff group under a holding group or a line unit, and one with no name', function () {
    $units = app(UnitRepository::class);
    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $eloa = $units->create(UnitType::Group, 'Extended Leave of Absence', 'ELOA', $regiment->id, UnitKind::Eloa);
    $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/settings/org-structure', ['type' => 'group', 'parent_id' => $eloa->id, 'name' => 'Nope'])->assertSessionHasErrors('type');
    $this->actingAs($admin)->post('/settings/org-structure', ['type' => 'group', 'parent_id' => $battalion->id, 'name' => 'Nope'])->assertSessionHasErrors('type');
    $this->actingAs($admin)->post('/settings/org-structure', ['type' => 'group', 'parent_id' => $regiment->id, 'name' => ' '])->assertSessionHasErrors('name');

    expect(Unit::query()->where('name', 'Nope')->exists())->toBeFalse();
});

it('refuses a staff group to anyone but an Admin', function () {
    $regiment = app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);

    $this->actingAs(User::factory()->serving()->create())
        ->post('/settings/org-structure', ['type' => 'group', 'parent_id' => $regiment->id, 'name' => 'Discord'])
        ->assertForbidden();
});
