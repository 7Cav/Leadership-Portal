<?php

use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitActingLeadRepository;
use App\Repositories\UnitRepository;

// #443: the roster tags each trooper with the short name of the command billet they hold in their unit (CO, XO, 1SG,
// PL, PSG, SL, ASL, SGM, CSM), an acting lead as "Acting PL" and the like, and everyone else with nothing. The tag
// reads the org import's billet to unit mapping and the billet's title. Fixture tree:
//   7CAV ─ 1-7 ─ A/1-7 ─ 1/A/1-7 ─┬─ 1/1/A/1-7
//                                  └─ 2/1/A/1-7

/** A trooper placed in `$placedIn` whose billet `$title` commands `$commands` (the org import's mapping). */
function btHolder(Unit $commands, string $title, ?Unit $placedIn = null, array $attributes = []): Trooper
{
    static $position = 9000;
    $position++;
    UnitCommandMapping::factory()->create(['unit_id' => $commands->id, 'cav_position_id' => $position]);
    $trooper = Trooper::factory()->create(['cav_position_id' => $position, 'billet' => $title, ...$attributes]);
    $trooper->forceFill(['primary_section_id' => ($placedIn ?? $commands)->id])->save();

    return $trooper;
}

function btPlaced(Unit $unit, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $unit->id])->save();

    return $trooper;
}

/** @return array<int, ?string> the roster's tag per trooper id, with the whole regiment open */
function btTags($test): array
{
    $props = $test->actingAs($test->admin)->get("/roster/{$test->regiment->id}")->assertOk()->viewData('page')['props'];

    return collect($props['troopers'])->mapWithKeys(fn (array $row) => [$row['id'] => $row['role']])->all();
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->section2 = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->platoon->id);
    $this->admin = User::factory()->admin()->create();
});

it('tags each command billet with its short name on every echelon', function () {
    $held = [
        'CO' => btHolder($this->regiment, 'Regimental Commanding Officer'),
        'XO' => btHolder($this->regiment, 'Regimental Executive Officer'),
        'CSM' => btHolder($this->regiment, 'Regimental Command Sergeant Major'),
        'bn CO' => btHolder($this->battalion, '1-7 Commanding Officer'),
        'bn XO' => btHolder($this->battalion, '1-7 Executive Officer'),
        'bn SGM' => btHolder($this->battalion, '1-7 Sergeant Major'),
        'co CO' => btHolder($this->company, 'Commander A/1-7'),
        'co XO' => btHolder($this->company, 'Executive Officer A/1-7'),
        'co 1SG' => btHolder($this->company, 'First Sergeant A/1-7'),
        'PL' => btHolder($this->platoon, 'Platoon Leader 1/A/1-7'),
        'PSG' => btHolder($this->platoon, 'Platoon Sergeant 1/A/1-7'),
        'SL' => btHolder($this->section, 'Section Leader 1/1/A/1-7'),
        'ASL' => btHolder($this->section, 'Assistant Section Leader 1/1/A/1-7'),
    ];

    $tags = btTags($this);

    expect(collect($held)->map(fn (Trooper $t) => $tags[$t->id])->all())->toBe([
        'CO' => 'CO', 'XO' => 'XO', 'CSM' => 'CSM',
        'bn CO' => 'CO', 'bn XO' => 'XO', 'bn SGM' => 'SGM',
        'co CO' => 'CO', 'co XO' => 'XO', 'co 1SG' => '1SG',
        'PL' => 'PL', 'PSG' => 'PSG',
        'SL' => 'SL', 'ASL' => 'ASL',
    ]);
});

it('tags ACD\'s own spelling of its battalion command team', function () {
    $acd = app(UnitRepository::class)->create(UnitType::Battalion, 'Auxiliary Combat Division', 'ACD', $this->regiment->id);
    $co = btHolder($acd, 'Auxiliary Commander');
    $xo = btHolder($acd, 'Auxiliary Executive Officer');
    $sgm = btHolder($acd, 'Auxiliary Sergeant Major');

    $tags = btTags($this);

    expect([$tags[$co->id], $tags[$xo->id], $tags[$sgm->id]])->toBe(['CO', 'XO', 'SGM']);
});

it('gives no tag to anyone without a command billet of their unit, not just "SL" to every leader', function () {
    $rifleman = btPlaced($this->section, ['billet' => 'Rifleman']);
    // A billet title that sounds like a leader's but commands nothing.
    $trainer = btPlaced($this->section, ['billet' => 'Training Leader']);
    // A company staff billet placed on the company, mapped to nothing.
    $clerk = btPlaced($this->company, ['billet' => 'Company Clerk A/1-7']);
    // A command billet holder an Admin placed in another section: they hold no command billet of that one.
    $moved = btHolder($this->section, 'Section Leader 1/1/A/1-7', $this->section2);
    // A departed holder's billet confers nothing.
    $gone = btHolder($this->platoon, 'Platoon Leader 1/A/1-7', null, ['status' => TrooperStatus::Inactive]);

    $tags = btTags($this);

    expect($tags[$rifleman->id])->toBeNull()
        ->and($tags[$trainer->id])->toBeNull()
        ->and($tags[$clerk->id])->toBeNull()
        ->and($tags[$moved->id])->toBeNull()
        ->and($tags[$gone->id] ?? null)->toBeNull()
        ->and(array_filter($tags))->toBe([]);
});

it('tags an acting lead as acting for the billet they stand in for', function () {
    $acting = app(UnitActingLeadRepository::class);
    $actingPl = btPlaced($this->section, ['billet' => 'Rifleman']);
    $actingCo = btPlaced($this->company, ['billet' => 'Company Clerk A/1-7']);
    $actingSl = btPlaced($this->section2, ['billet' => 'Rifleman']);
    $acting->assign($this->platoon->id, $actingPl->id, $this->admin->id);
    $acting->assign($this->company->id, $actingCo->id, $this->admin->id);
    $acting->assign($this->section2->id, $actingSl->id, $this->admin->id);

    $tags = btTags($this);

    expect($tags[$actingPl->id])->toBe('Acting PL')
        ->and($tags[$actingCo->id])->toBe('Acting CO')
        ->and($tags[$actingSl->id])->toBe('Acting SL');
});

it('keeps an acting lead\'s own tag while a billet holder leads the unit', function () {
    $pl = btHolder($this->platoon, 'Platoon Leader 1/A/1-7');
    $sl = btHolder($this->section, 'Section Leader 1/1/A/1-7');
    app(UnitActingLeadRepository::class)->assign($this->platoon->id, $sl->id, $this->admin->id);

    $tags = btTags($this);

    expect($tags[$sl->id])->toBe('SL')
        ->and($tags[$pl->id])->toBe('PL');
});

it('names the billet on the trooper sheet, for the lead and for the rest of the command team', function () {
    $sl = btHolder($this->section, 'Section Leader 1/1/A/1-7');
    $asl = btHolder($this->section, 'Assistant Section Leader 1/1/A/1-7');
    $rifleman = btPlaced($this->section, ['billet' => 'Rifleman']);
    $actingPl = btPlaced($this->platoon, ['billet' => 'Platoon Staff 1/A/1-7']);
    app(UnitActingLeadRepository::class)->assign($this->platoon->id, $actingPl->id, $this->admin->id);

    $sheet = fn (Trooper $t) => $this->actingAs($this->admin)->getJson("/roster/troopers/{$t->id}")->assertOk()->json('trooper');

    expect($sheet($sl)['role'])->toBe('SL')
        ->and($sheet($sl)['sectionLeader'])->toMatchArray(['id' => $sl->id, 'source' => 'billet'])
        ->and($sheet($asl)['role'])->toBe('ASL')
        ->and($sheet($asl)['sectionLeader']['id'])->toBe($sl->id)
        ->and($sheet($rifleman)['role'])->toBeNull()
        ->and($sheet($actingPl)['role'])->toBe('Acting PL')
        ->and($sheet($actingPl)['sectionLeader'])->toMatchArray(['id' => $actingPl->id, 'source' => 'acting']);
});

it('tags the readiness matrix with the same rule', function () {
    $pl = btHolder($this->platoon, 'Platoon Leader 1/A/1-7');
    $psg = btHolder($this->platoon, 'Platoon Sergeant 1/A/1-7');
    $sl = btHolder($this->section, 'Section Leader 1/1/A/1-7');
    $rifleman = btPlaced($this->section, ['billet' => 'Rifleman']);

    $this->actingAs($this->admin)->get(route('readiness.matrix', $this->platoon->id))->assertOk()
        ->assertInertia(fn ($page) => $page->where('troopers', fn ($rows) => collect($rows)->mapWithKeys(fn ($row) => [$row['id'] => $row['role']])->all() == [
            $pl->id => 'PL', $psg->id => 'PSG', $sl->id => 'SL', $rifleman->id => null,
        ]));
});
