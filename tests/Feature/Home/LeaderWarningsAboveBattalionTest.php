<?php

use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;

// Home's leader warnings (missing identities, missing practice slots) are for leaders at battalion level or below.

beforeEach(function () {
    $node = function (UnitType $type, ?Unit $parent, array $attributes = []): Unit {
        $unit = Unit::factory()->create(['type' => $type, 'parent_id' => $parent?->id, ...$attributes]);
        $unit->forceFill(['path' => ($parent?->path ?? '')."{$unit->id}/"])->save();

        return $unit;
    };
    $this->regiment = $node(UnitType::Regiment, null);
    $this->rhq = $node(UnitType::Group, $this->regiment, ['kind' => UnitKind::Staff]);
    $this->battalion = $node(UnitType::Battalion, $this->regiment);
    $this->company = $node(UnitType::Company, $this->battalion);
    $this->platoon = $node(UnitType::Platoon, $this->company);
    $this->section = $node(UnitType::Section, $this->platoon, ['callsign' => '1/1/A']);
    $this->battalion->forceFill(['game_id' => game('hll-vietnam')->id])->save();
    // Serving, no identity linked, and the section has no slot: both warnings apply.
    Trooper::factory()->create()->forceFill(['primary_section_id' => $this->section->id])->save();
});

function seesLeaderWarnings(User $user): bool
{
    $props = test()->actingAs($user)->get('/home')->viewData('page')['props'];

    return $props['missingIdentities'] !== [] && $props['sectionsWithoutSlot'] !== [];
}

function seesNoLeaderWarnings(User $user): bool
{
    $props = test()->actingAs($user)->get('/home')->viewData('page')['props'];

    return $props['missingIdentities'] === [] && $props['sectionsWithoutSlot'] === [];
}

it('warns a battalion leader and the leaders below', function () {
    expect(seesLeaderWarnings(User::factory()->commanding($this->battalion)->create()))->toBeTrue()
        ->and(seesLeaderWarnings(User::factory()->commanding($this->platoon)->create()))->toBeTrue();
});

it('does not warn a regiment leader or a Regimental HQ leader', function () {
    expect(seesNoLeaderWarnings(User::factory()->commanding($this->regiment)->create()))->toBeTrue()
        ->and(seesNoLeaderWarnings(User::factory()->commanding($this->rhq)->create()))->toBeTrue();
});

it('does not warn an Admin unless they view as a unit at battalion level or below', function () {
    $admin = User::factory()->admin()->create();
    expect(seesNoLeaderWarnings($admin))->toBeTrue();

    $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->battalion->id]);
    expect(seesLeaderWarnings($admin))->toBeTrue();

    $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->regiment->id]);
    expect(seesNoLeaderWarnings($admin))->toBeTrue();
});
