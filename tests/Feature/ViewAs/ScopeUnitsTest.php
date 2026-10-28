<?php

use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

// The sidebar's unit switcher (#426): the `scopeTop` prop every page shares and the `/scope/units` list its menu reads.
// Both follow the effective scope, view as included, and never list a unit outside it.

/**
 * 7CAV → 1-7 → A/1-7 → 1/A/1-7 → {1/1/A/1-7, 2/1/A/1-7, 3/1/A/1-7 (empty)}, a staff group S1 under 1-7, and a
 * sibling battalion 2-7 with one section.
 *
 * @return array<string, Unit>
 */
function suTree(): array
{
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
    $platoon = $repo->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $section = $repo->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);
    $section2 = $repo->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $platoon->id);
    $empty = $repo->create(UnitType::Section, '3rd Section', '3/1/A/1-7', $platoon->id);
    $staff = $repo->create(UnitType::Group, 'S1 Personnel', 'S1', $battalion->id, UnitKind::Staff);
    $otherBattalion = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);
    $otherCompany = $repo->create(UnitType::Company, 'Echo Company', 'E/2-7', $otherBattalion->id);
    $otherPlatoon = $repo->create(UnitType::Platoon, '1st Platoon', '1/E/2-7', $otherCompany->id);
    $otherSection = $repo->create(UnitType::Section, '1st Section', '1/1/E/2-7', $otherPlatoon->id);

    foreach ([$section, $section2, $otherSection] as $placed) {
        Trooper::factory()->create(['status' => TrooperStatus::Combat])->forceFill(['primary_section_id' => $placed->id])->save();
    }

    return compact('regiment', 'battalion', 'company', 'platoon', 'section', 'section2', 'empty', 'staff', 'otherBattalion', 'otherCompany', 'otherPlatoon', 'otherSection');
}

function suLeaderOf(Unit $unit, int $cavId): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $cavId + 10000]);
    Trooper::factory()->create(['cav_id' => $cavId, 'cav_position_id' => $cavId + 10000, 'status' => TrooperStatus::Combat]);

    return User::factory()->create(['cav_id' => $cavId]);
}

/** @return list<int> */
function suListed(): array
{
    return collect(test()->getJson('/scope/units')->assertOk()->json('units'))->pluck('id')->all();
}

describe('the units list', function () {
    it('lists a company commander\'s units as a tree, sections included and the empty section left out', function () {
        $tree = suTree();
        $commander = suLeaderOf($tree['company'], 100);

        $this->actingAs($commander)->getJson('/scope/units')
            ->assertOk()
            ->assertExactJson(['units' => [
                ['id' => $tree['company']->id, 'designation' => 'A/1-7', 'name' => 'Alpha Company', 'type' => 'company', 'line' => true, 'depth' => 0],
                ['id' => $tree['platoon']->id, 'designation' => '1/A/1-7', 'name' => '1st Platoon', 'type' => 'platoon', 'line' => true, 'depth' => 1],
                ['id' => $tree['section']->id, 'designation' => '1/1/A/1-7', 'name' => '1st Section', 'type' => 'section', 'line' => true, 'depth' => 2],
                ['id' => $tree['section2']->id, 'designation' => '2/1/A/1-7', 'name' => '2nd Section', 'type' => 'section', 'line' => true, 'depth' => 2],
            ]]);
    });

    it('never lists a unit outside the scope, for any echelon', function (string $led) {
        $tree = suTree();
        $leader = suLeaderOf($tree[$led], 100);
        $subtree = app(UnitRepository::class)->subtreeIds($tree[$led])->all();

        $this->actingAs($leader);

        expect(suListed())->not->toBeEmpty()->each->toBeIn($subtree);
    })->with(['section', 'platoon', 'company', 'battalion', 'otherBattalion']);

    it('keeps a staff group, which has no troopers to count', function () {
        $tree = suTree();
        $commander = suLeaderOf($tree['battalion'], 100);

        $this->actingAs($commander);

        expect(suListed())->toContain($tree['staff']->id)->not->toContain($tree['empty']->id);
    });

    it('lists each granted unit as a top of its own, and nothing between them', function () {
        $tree = suTree();
        $staff = User::factory()->serving()->create();
        PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $tree['platoon']->id]);
        PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $tree['otherCompany']->id]);

        $units = collect($this->actingAs($staff)->getJson('/scope/units')->json('units'))->keyBy('id');

        expect($units->keys()->all())->not->toContain($tree['company']->id, $tree['otherBattalion']->id, $tree['regiment']->id)
            ->and($units[$tree['platoon']->id]['depth'])->toBe(0)
            ->and($units[$tree['otherCompany']->id]['depth'])->toBe(0)
            ->and($units[$tree['otherSection']->id]['depth'])->toBe(2);
    });

    it('follows view as: an Admin viewing as a section gets that section only', function () {
        $tree = suTree();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $tree['section']->id]);

        expect(suListed())->toBe([$tree['section']->id]);
    });

    it('follows a person preview: an Admin viewing as a platoon leader gets the platoon', function () {
        $tree = suTree();
        $admin = User::factory()->admin()->create();
        $platoonLeader = suLeaderOf($tree['platoon'], 100);
        $this->actingAs($admin)->post('/view-as', ['kind' => 'user', 'id' => $platoonLeader->id]);

        expect(suListed())->toBe([$tree['platoon']->id, $tree['section']->id, $tree['section2']->id]);
    });

    it('lists nothing for a user with no scope', function () {
        suTree();

        $this->actingAs(User::factory()->serving()->create())->getJson('/scope/units')->assertExactJson(['units' => []]);
    });

    it('requires authentication', function () {
        $this->getJson('/scope/units')->assertUnauthorized();
    });

    it('has a read limit of its own', function () {
        suTree();
        $this->actingAs(User::factory()->admin()->create());

        foreach (range(1, 30) as $ignored) {
            $this->getJson('/scope/units')->assertOk();
        }

        $this->getJson('/scope/units')->assertTooManyRequests();
    });
});

describe('the top of the scope every page shares', function () {
    it('is the regiment for an Admin', function () {
        $tree = suTree();

        $this->actingAs(User::factory()->admin()->create())->get('/home')
            ->assertInertia(fn ($page) => $page->where('scopeTop', [
                ['id' => $tree['regiment']->id, 'designation' => '7CAV', 'name' => '7th Cavalry Regiment'],
            ]));
    });

    it('is the section for its leader, and the section a preview narrows to', function () {
        $tree = suTree();
        $sectionLeader = suLeaderOf($tree['section'], 100);
        $commander = suLeaderOf($tree['battalion'], 200);

        $this->actingAs($sectionLeader)->get('/home')->assertInertia(fn ($page) => $page->where('scopeTop.0.id', $tree['section']->id)->has('scopeTop', 1));

        $this->actingAs($commander)->post('/view-as', ['kind' => 'unit', 'id' => $tree['section2']->id]);
        $this->get('/home')->assertInertia(fn ($page) => $page->where('scopeTop.0.id', $tree['section2']->id)->has('scopeTop', 1));
    });

    it('is each granted unit for a staff grant holder', function () {
        $tree = suTree();
        $staff = User::factory()->serving()->create();
        PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $tree['platoon']->id]);
        PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $tree['otherCompany']->id]);

        $this->actingAs($staff)->get('/home')->assertInertia(fn ($page) => $page
            ->where('scopeTop.0.id', $tree['platoon']->id)
            ->where('scopeTop.1.id', $tree['otherCompany']->id)
            ->has('scopeTop', 2));
    });

    it('leaves out a granted unit with no troopers, as the menu does, so "+N" counts only what it lists', function () {
        $tree = suTree();
        $staff = User::factory()->serving()->create();
        PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $tree['otherCompany']->id]);
        PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $tree['empty']->id]);

        $this->actingAs($staff)->get('/home')->assertInertia(fn ($page) => $page
            ->where('scopeTop', [['id' => $tree['otherCompany']->id, 'designation' => 'E/2-7', 'name' => 'Echo Company']]));
        expect(suListed())->not->toContain($tree['empty']->id);
    });

    it('keeps the first top when every one is empty, so the label is never blank', function () {
        $tree = suTree();
        $second = app(UnitRepository::class)->create(UnitType::Section, '4th Section', '4/1/A/1-7', $tree['platoon']->id);
        $staff = User::factory()->serving()->create();
        PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $tree['empty']->id]);
        PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $second->id]);

        $this->actingAs($staff)->get('/home')->assertInertia(fn ($page) => $page
            ->where('scopeTop', [['id' => $tree['empty']->id, 'designation' => '3/1/A/1-7', 'name' => '3rd Section']]));
    });

    it('is empty for a user with no scope', function () {
        suTree();

        $this->actingAs(User::factory()->serving()->create())->get('/home')->assertInertia(fn ($page) => $page->where('scopeTop', []));
    });
});
