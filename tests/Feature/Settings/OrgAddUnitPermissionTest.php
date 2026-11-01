<?php

use App\Models\AuditLogEntry;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitOversight;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Illuminate\Support\Facades\DB;

// Who may add a unit on Org structure: an Admin anywhere; a leader only inside the unit they command or oversee, and at
// least two echelons below it (a section needs a company or above, a platoon a battalion or above, a company the
// regiment). Everyone else is refused, server-side.

/** A serving trooper who leads `$unit` (acting lead, the same command a billet gives), and their account. */
function oaLeaderOf(Unit $unit): User
{
    $trooper = Trooper::factory()->create(['status' => TrooperStatus::Combat]);
    DB::table('unit_acting_leads')->insert(['unit_id' => $unit->id, 'trooper_id' => $trooper->id, 'created_at' => now(), 'updated_at' => now()]);

    return User::factory()->create(['cav_id' => $trooper->cav_id]);
}

function oaAdd(object $test, User $as, Unit $parent, string $type, string $ordinal)
{
    return $test->actingAs($as)->post('/settings/org-structure', ['type' => $type, 'parent_id' => $parent->id, 'ordinal' => $ordinal]);
}

/** @return array<int, array<string, mixed>> the page's units by id */
function oaUnits($response): array
{
    return collect($response->viewData('page')['props']['units'])->keyBy('id')->all();
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '3rd Battalion', '3-7', $this->regiment->id);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/3-7', $this->battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/3-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/3-7', $this->platoon->id);
    $this->otherCompany = $units->create(UnitType::Company, 'Bravo Company', 'B/3-7', $this->battalion->id);
    $this->otherPlatoon = $units->create(UnitType::Platoon, '1st Platoon', '1/B/3-7', $this->otherCompany->id);
    $this->otherBattalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
});

it('refuses a section leader adding a section', function () {
    oaAdd($this, oaLeaderOf($this->section), $this->platoon, 'section', '2')->assertForbidden();

    expect(Unit::where('callsign', '2/1/A/3-7')->exists())->toBeFalse();
});

it('refuses a platoon leader adding a section, one echelon is not enough', function () {
    oaAdd($this, oaLeaderOf($this->platoon), $this->platoon, 'section', '2')->assertForbidden();
});

it('lets a company commander add a section in their company, audited, but not in another company', function () {
    $co = oaLeaderOf($this->company);

    oaAdd($this, $co, $this->platoon, 'section', '2')->assertRedirect('/settings/org-structure')->assertSessionHasNoErrors();
    expect(Unit::where('callsign', '2/1/A/3-7')->value('name'))->toBe('2nd Section')
        ->and(AuditLogEntry::where('action', 'unit.created')->where('actor_id', $co->id)->count())->toBe(1);

    oaAdd($this, $co, $this->otherPlatoon, 'section', '2')->assertForbidden();
    expect(Unit::where('callsign', '2/1/B/3-7')->exists())->toBeFalse();
});

it('refuses a company commander adding a platoon', function () {
    oaAdd($this, oaLeaderOf($this->company), $this->company, 'platoon', '2')->assertForbidden();
});

it('lets a battalion commander add a platoon in their battalion, but not in another battalion, nor a company', function () {
    $bc = oaLeaderOf($this->battalion);

    oaAdd($this, $bc, $this->otherCompany, 'platoon', '2')->assertSessionHasNoErrors();
    expect(Unit::where('callsign', '2/B/3-7')->exists())->toBeTrue();

    oaAdd($this, $bc, $this->battalion, 'company', 'C')->assertForbidden();

    $foreign = app(UnitRepository::class)->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->otherBattalion->id);
    oaAdd($this, $bc, $foreign, 'platoon', '1')->assertForbidden();
});

it('lets the overseer of a battalion add its companies and platoons, in that battalion only, and no battalion', function () {
    $trooper = Trooper::factory()->create(['status' => TrooperStatus::Combat]);
    UnitOversight::query()->create(['unit_id' => $this->battalion->id, 'trooper_id' => $trooper->id]);
    $overseer = User::factory()->create(['cav_id' => $trooper->cav_id]);

    oaAdd($this, $overseer, $this->company, 'platoon', '2')->assertSessionHasNoErrors();
    oaAdd($this, $overseer, $this->battalion, 'company', 'C')->assertSessionHasNoErrors();
    expect(Unit::where('callsign', 'C/3-7')->value('name'))->toBe('Charlie Company');
    oaAdd($this, $overseer, $this->regiment, 'battalion', '2')->assertForbidden();

    $foreign = app(UnitRepository::class)->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->otherBattalion->id);
    oaAdd($this, $overseer, $foreign, 'platoon', '1')->assertForbidden();
});

it('lets the regiment commander add a company but leaves battalions to Admins', function () {
    $rc = oaLeaderOf($this->regiment);

    oaAdd($this, $rc, $this->battalion, 'company', 'C')->assertSessionHasNoErrors();
    oaAdd($this, $rc, $this->regiment, 'battalion', '2')->assertForbidden();
});

it('lets an Admin add anything, and refuses a trooper who leads nothing', function () {
    $admin = User::factory()->admin()->create();
    oaAdd($this, $admin, $this->regiment, 'battalion', '2')->assertSessionHasNoErrors();
    oaAdd($this, $admin, $this->battalion, 'company', 'C')->assertSessionHasNoErrors();

    oaAdd($this, User::factory()->serving()->create(), $this->platoon, 'section', '2')->assertForbidden();
});

it('flags the units a leader may add under, and none while previewing', function () {
    $co = oaLeaderOf($this->company);
    $units = oaUnits($this->actingAs($co)->get('/settings/org-structure'));

    expect($units[$this->platoon->id]['can_add_child'])->toBeTrue()
        ->and($units[$this->company->id]['can_add_child'])->toBeFalse()
        ->and($units[$this->otherPlatoon->id]['can_add_child'])->toBeFalse()
        ->and($units[$this->section->id]['can_add_child'])->toBeFalse();

    $admin = User::factory()->admin()->create();
    expect(oaUnits($this->actingAs($admin)->get('/settings/org-structure'))[$this->regiment->id]['can_add_child'])->toBeTrue();

    $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->platoon->id]);
    expect(oaUnits($this->actingAs($admin)->get('/settings/org-structure'))[$this->regiment->id]['can_add_child'])->toBeFalse();
});
