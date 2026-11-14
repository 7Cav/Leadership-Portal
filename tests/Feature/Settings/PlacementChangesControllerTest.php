<?php

use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

// Settings → Placement changes: the Admin-only list of every move between units, with a move undone straight away marked
// as a likely wrong milpac edit.

beforeEach(function () {
    $this->admin = User::factory()->serving()->admin()->create(['username' => 'Pike.S']);
    $units = app(UnitRepository::class);
    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $units->create(UnitType::Company, 'Alpha', 'A/1-7', $battalion->id);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $this->one = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);
    $this->two = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $platoon->id);
});

function pcTrooper(Unit $section, array $attributes): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

it('refuses a non-Admin with 403 (AD-6)', function () {
    $this->actingAs(User::factory()->serving()->create())->get('/settings/placement-changes')->assertForbidden();
});

it('lists the moves of the span newest first, marking a move put straight back, and leaves out older ones', function () {
    $gentry = pcTrooper($this->one, ['username' => 'Gentry.C', 'rank_short' => 'PFC']);
    $mover = pcTrooper($this->one, ['username' => 'Adams.P', 'rank_short' => 'SPC']);
    $sync = app(TrooperRepository::class);

    // Three and a half weeks ago: outside the default 14 days, inside 30.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    $sync->updatePrimarySections([$mover->cav_id => $this->two->id]);
    // Today: the wrong Gentry moved at one sync and put back at the next.
    $this->travelTo(CarbonImmutable::parse('2026-11-14 18:00:00', 'UTC'));
    $sync->updatePrimarySections([$gentry->cav_id => $this->two->id]);
    $this->travelTo(CarbonImmutable::parse('2026-11-14 19:00:00', 'UTC'));
    $sync->updatePrimarySections([$gentry->cav_id => $this->one->id]);
    $this->travelTo(CarbonImmutable::parse('2026-11-14 20:00:00', 'UTC'));

    $this->actingAs($this->admin)->get('/settings/placement-changes')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Settings/PlacementChanges')
        ->where('days', 14)
        ->where('changes', [
            ['at' => '2026-11-14T19:00:00Z', 'trooperId' => $gentry->id, 'trooper' => 'PFC Gentry.C', 'from' => '2/1/A/1-7', 'to' => '1/1/A/1-7', 'source' => 'sync', 'reversedAt' => null, 'reverses' => true],
            ['at' => '2026-11-14T18:00:00Z', 'trooperId' => $gentry->id, 'trooper' => 'PFC Gentry.C', 'from' => '1/1/A/1-7', 'to' => '2/1/A/1-7', 'source' => 'sync', 'reversedAt' => '2026-11-14T19:00:00Z', 'reverses' => false],
        ]));

    $this->actingAs($this->admin)->get('/settings/placement-changes?days=30')->assertInertia(fn (Assert $page) => $page
        ->where('days', 30)
        ->has('changes', 3)
        ->where('changes.2.trooper', 'SPC Adams.P'));
});

it('takes only the spans it offers', function () {
    $this->actingAs($this->admin)->get('/settings/placement-changes?days=365')->assertSessionHasErrors('days');
});
