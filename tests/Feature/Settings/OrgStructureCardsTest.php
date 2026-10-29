<?php

use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitKind;
use App\Models\UnitOversight;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

// The org page after the S6's requests of 2026-10-29: empty line units are flagged (hidden by the page until asked for,
// #442's one definition), the staff tree and the line come as one list the page splits in two, ACD and DEVCOM show
// again under RDC and DEVCOM's staff under DEVCOM, and each battalion and the Reservists carry their overseer.

/** @return array<int, array<string, mixed>> */
function ocUnits($response): array
{
    return collect($response->viewData('page')['props']['units'])->keyBy('id')->all();
}

function ocPlace(Unit $unit, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create(['status' => TrooperStatus::Combat, ...$attributes]);
    $trooper->forceFill(['primary_section_id' => $unit->id])->save();

    return $trooper;
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->first = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->alpha = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->first->id);
    $this->echo = $units->create(UnitType::Company, 'Echo Company', 'E/1-7', $this->first->id);
    $this->third = $units->create(UnitType::Battalion, '3rd Battalion', '3-7', $this->regiment->id);
    $this->acd = $units->create(UnitType::Battalion, 'Auxiliary Combat Detachment', 'ACD', $this->regiment->id);
    $this->devcom = $units->create(UnitType::Battalion, 'DEVCOM', 'DEVCOM', $this->regiment->id);
    $this->xo = $units->create(UnitType::Group, 'Executive Officer', 'XO', $this->regiment->id, UnitKind::Staff);
    $this->rdc = $units->create(UnitType::Group, 'Reserve & Development Command', 'RDC', $this->xo->id, UnitKind::Staff);
    $this->devcomStaff = $units->create(UnitType::Group, 'DEVCOM Staff', 'DEVCOM STAFF', $this->rdc->id, UnitKind::Staff);
    $this->reserve = $units->create(UnitType::Group, 'Reservists', 'RES', $this->rdc->id, UnitKind::Reserve);
    $this->s1 = $units->create(UnitType::Group, 'S1', 'S1', $this->regiment->id, UnitKind::Staff);

    ocPlace($this->alpha);
    ocPlace($this->acd);
    ocPlace($this->devcom);
    $this->admin = User::factory()->admin()->create();
});

it('flags the line units with nobody in them or under them, and never a group', function () {
    $units = ocUnits($this->actingAs($this->admin)->get('/settings/org-structure'));

    expect($units[$this->echo->id]['empty'])->toBeTrue()
        ->and($units[$this->third->id]['empty'])->toBeTrue()
        ->and($units[$this->alpha->id]['empty'])->toBeFalse()
        ->and($units[$this->first->id]['empty'])->toBeFalse()
        ->and($units[$this->regiment->id]['empty'])->toBeFalse()
        // Every group is kept whatever its headcount, as #442 keeps them.
        ->and($units[$this->s1->id]['empty'])->toBeFalse()
        // Still sent, so an Admin can show one and rename, move or delete it.
        ->and($units)->toHaveKey($this->echo->id);
});

it('stops flagging a unit as soon as a trooper is placed in it', function () {
    ocPlace($this->echo);

    expect(ocUnits($this->actingAs($this->admin)->get('/settings/org-structure'))[$this->echo->id]['empty'])->toBeFalse();
});

it('sends the reference rows: ACD and DEVCOM under RDC, DEVCOM\'s staff under DEVCOM', function () {
    $this->actingAs($this->admin)->get('/settings/org-structure')
        ->assertInertia(fn ($page) => $page->where('references', [
            ['parent_id' => $this->rdc->id, 'unit_id' => $this->acd->id],
            ['parent_id' => $this->rdc->id, 'unit_id' => $this->devcom->id],
            ['parent_id' => $this->devcom->id, 'unit_id' => $this->devcomStaff->id],
        ]));

    // Nothing moved: each unit keeps its own parent.
    expect(Unit::find($this->acd->id)->parent_id)->toBe($this->regiment->id)
        ->and(Unit::find($this->devcomStaff->id)->parent_id)->toBe($this->rdc->id);
});

it('leaves out a reference whose unit is not there', function () {
    $this->devcomStaff->delete();

    $this->actingAs($this->admin)->get('/settings/org-structure')
        ->assertInertia(fn ($page) => $page->has('references', 2)->where('references.1.unit_id', $this->devcom->id));
});

it('shows each battalion\'s overseer, vacant when there is none, and marks one who left', function () {
    $rob = Trooper::factory()->create(['username' => 'Rob.Y', 'status' => TrooperStatus::Combat]);
    $rogers = Trooper::factory()->create(['username' => 'Rogers.S', 'status' => TrooperStatus::Combat]);
    $gone = Trooper::factory()->create(['username' => 'Gone.G', 'status' => TrooperStatus::Inactive]);
    UnitOversight::query()->create(['unit_id' => $this->first->id, 'trooper_id' => $rob->id]);
    UnitOversight::query()->create(['unit_id' => $this->reserve->id, 'trooper_id' => $rogers->id]);
    UnitOversight::query()->create(['unit_id' => $this->acd->id, 'trooper_id' => $gone->id]);

    // Anyone signed in reads the page, as before.
    $units = ocUnits($this->actingAs(User::factory()->serving()->create())->get('/settings/org-structure'));

    expect($units[$this->first->id]['oversight'])->toMatchArray(['trooperId' => $rob->id, 'serving' => true])
        ->and($units[$this->first->id]['oversight']['displayName'])->toContain('Rob.Y')
        ->and($units[$this->first->id]['oversight_vacant'])->toBeFalse()
        ->and($units[$this->third->id]['oversight'])->toBeNull()
        ->and($units[$this->third->id]['oversight_vacant'])->toBeTrue()
        ->and($units[$this->acd->id]['oversight']['serving'])->toBeFalse()
        ->and($units[$this->acd->id]['oversight_vacant'])->toBeTrue()
        ->and($units[$this->reserve->id]['oversight']['displayName'])->toContain('Rogers.S')
        // Only a battalion reads vacant; only a battalion or the Reservists take an overseer.
        ->and($units[$this->reserve->id]['oversight_vacant'])->toBeFalse()
        ->and($units[$this->alpha->id]['oversight_vacant'])->toBeFalse()
        ->and($units[$this->first->id]['overseeable'])->toBeTrue()
        ->and($units[$this->reserve->id]['overseeable'])->toBeTrue()
        ->and($units[$this->alpha->id]['overseeable'])->toBeFalse()
        ->and($units[$this->rdc->id]['overseeable'])->toBeFalse();
});
