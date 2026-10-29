<?php

use App\Models\CounselingRecord;
use App\Models\CrossSectionSighting;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/fixture.php';

// Audit 3 (2026-10-29): the own milpac page is the real signed-in person's, as themselves. Acting as someone never opens
// it for the one acting: a senior acting as a junior of their own command team sees no DCS the senior could not see as
// themselves, and a leader acting as someone placed outside the leader's scope does not open that milpac.
// Tree: 7CAV ─ 1-7 ─┬─ A/1-7 ─ 1/A/1-7 ─┬─ 1/1/A/1-7
//                   │                  └─ 2/1/A/1-7
//                   └─ B/1-7 ─ 1/B/1-7 ─ 1/1/B/1-7

/** A serving trooper on `$billet`, whose position commands `$commands`, placed in `$placedIn` (default: that unit). */
function ownActingHolder(int $cavId, string $username, string $billet, Unit $commands, ?Unit $placedIn = null): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $commands->id, 'cav_position_id' => 7000 + $cavId]);
    Trooper::factory()->create([
        'cav_id' => $cavId, 'cav_position_id' => 7000 + $cavId, 'billet' => $billet, 'status' => TrooperStatus::Combat,
        'username' => $username, 'primary_section_id' => ($placedIn ?? $commands)->id,
    ]);

    return User::factory()->create(['cav_id' => $cavId, 'username' => $username]);
}

function ownActingTrooper(User $user): Trooper
{
    return Trooper::query()->where('cav_id', $user->cav_id)->sole();
}

/** @return list<int> the ids of the counseling records the milpac page lists for whoever the request runs as */
function ownActingRecords(object $test, User $as, Trooper $trooper): array
{
    return collect($test->actingAs($as)->get(route('troopers.show', $trooper->id))->assertOk()->viewData('page')['props']['counseling']['records'])
        ->pluck('id')->all();
}

beforeEach(function () {
    Storage::fake('counseling');
    $units = app(UnitRepository::class);
    $this->units = [];
    $this->units['regiment'] = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->units['battalion'] = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->units['regiment']->id);
    $this->units['company'] = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->units['battalion']->id);
    $this->units['platoon'] = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->units['company']->id);
    $this->units['section'] = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->units['platoon']->id);
    $this->units['bravo'] = $units->create(UnitType::Company, 'Bravo Company', 'B/1-7', $this->units['battalion']->id);
    $this->units['bPlatoon'] = $units->create(UnitType::Platoon, '1st Platoon', '1/B/1-7', $this->units['bravo']->id);
    $this->units['bSection'] = $units->create(UnitType::Section, '1st Section', '1/1/B/1-7', $this->units['bPlatoon']->id);
});

it('shows a senior acting as their command-team junior none of the junior\'s DCSs, and serves no download', function (string $unit, string $senior, string $junior, ?string $issuer) {
    $seniorUser = ownActingHolder(100, 'Senior.S', $senior, $this->units[$unit]);
    $juniorUser = ownActingHolder(101, 'Junior.J', $junior, $this->units[$unit]);
    $juniorTrooper = ownActingTrooper($juniorUser);
    $dcs = CounselingRecord::factory()->create([
        'trooper_id' => $juniorTrooper->id, 'issuer_unit_id' => $issuer === null ? null : $this->units[$issuer]->id,
    ]);
    $lor = CounselingRecord::factory()->lor()->create(['trooper_id' => $juniorTrooper->id, 'issuer_unit_id' => $dcs->issuer_unit_id]);
    Storage::disk('counseling')->put($dcs->document_path, counselingPdfBytes('junior-dcs'));

    // As themselves: the junior shares their unit, so the senior sees the LOR and not the DCS.
    expect(ownActingRecords($this, $seniorUser, $juniorTrooper))->toBe([$lor->id]);
    $this->actingAs($seniorUser)->get(route('counseling.document', $dcs->id))->assertNotFound();

    // Acting as the junior (the command-team rule allows it) changes nothing about that.
    $this->actingAs($seniorUser)->post('/act-as', ['kind' => 'user', 'id' => $juniorUser->id])->assertRedirect('/home');

    $props = $this->actingAs($seniorUser)->get(route('troopers.show', $juniorTrooper->id))->assertOk()->viewData('page')['props'];
    expect(collect($props['counseling']['records'])->pluck('id')->all())->toBe([$lor->id])
        ->and($props['counseling']['sees']['dcs'])->toBeFalse();
    $this->actingAs($seniorUser)->get(route('counseling.document', $dcs->id))->assertNotFound();
})->with([
    'a Section Leader as their ASL' => ['section', 'Section Leader 1/1/A/1-7', 'Assistant Section Leader 1/1/A/1-7', 'platoon'],
    'a Platoon Leader as their Platoon Sergeant' => ['platoon', 'Platoon Leader 1/A/1-7', 'Platoon Sergeant 1/A/1-7', 'company'],
    'a company CO as their XO' => ['company', 'Commander A/1-7', 'Executive Officer A/1-7', 'battalion'],
    'a company CO as their First Sergeant' => ['company', 'Commander A/1-7', 'First Sergeant A/1-7', 'battalion'],
    'a battalion CO as their XO' => ['battalion', '1-7 Commanding Officer', '1-7 Executive Officer', 'regiment'],
    'a battalion CO as their Sergeant Major' => ['battalion', '1-7 Commanding Officer', '1-7 Sergeant Major', 'regiment'],
    'the RCO as the RXO' => ['regiment', 'Regimental Commanding Officer', 'Regimental Executive Officer', null],
    'the RCO as the RCSM' => ['regiment', 'Regimental Commanding Officer', 'Regimental Command Sergeant Major', null],
]);

it('keeps a leader acting as someone placed outside their scope out of that milpac, its documents, the search and staff practices', function () {
    $pl = ownActingHolder(150, 'Mercer.J', 'Platoon Leader 1/A/1-7', $this->units['platoon']);
    $secondSection = app(UnitRepository::class)->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->units['platoon']->id);
    // Their billet leads a section of the PL's platoon, but they are placed on Bravo's company HQ (a staff node).
    $crossUser = ownActingHolder(170, 'Cross.S', 'Section Leader 2/1/A/1-7', $secondSection, $this->units['bravo']);
    $cross = ownActingTrooper($crossUser);
    $dcs = CounselingRecord::factory()->create(['trooper_id' => $cross->id, 'issuer_unit_id' => $this->units['bPlatoon']->id]);
    $lor = CounselingRecord::factory()->lor()->create(['trooper_id' => $cross->id, 'issuer_unit_id' => $this->units['bPlatoon']->id]);
    Storage::disk('counseling')->put($lor->document_path, counselingPdfBytes('cross-lor'));

    $this->actingAs($pl)->get(route('troopers.show', $cross->id))->assertNotFound();
    $this->actingAs($pl)->post('/act-as', ['kind' => 'user', 'id' => $crossUser->id])->assertRedirect('/home');

    $this->actingAs($pl)->get(route('troopers.show', $cross->id))->assertNotFound();
    $this->actingAs($pl)->get(route('counseling.document', $lor->id))->assertNotFound();
    $this->actingAs($pl)->get(route('counseling.document', $dcs->id))->assertNotFound();
    expect($this->actingAs($pl)->getJson(route('troopers.search', ['q' => 'Cross']))->assertOk()->json('troopers'))->toBe([]);
    $this->actingAs($pl)->post(route('staff-practices.store', $cross->id), ['section_id' => $this->units['section']->id, 'week' => now()->toDateString()])
        ->assertNotFound();

    expect(CrossSectionSighting::query()->count())->toBe(0);

    // Signed in as themselves, the same trooper still opens their own page and finds themselves.
    $this->actingAs($pl)->delete('/act-as');
    $this->actingAs($crossUser)->get(route('troopers.show', $cross->id))->assertOk();
    expect(collect($this->actingAs($crossUser)->getJson(route('troopers.search', ['q' => 'Cross']))->assertOk()->json('troopers'))->pluck('id')->all())
        ->toBe([$cross->id]);
});
