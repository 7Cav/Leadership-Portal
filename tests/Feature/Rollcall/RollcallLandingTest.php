<?php

use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitActingLead;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

// Where the roster and the rollcall open when no unit is named (#426): a person preview opens where that person
// would, the rollcall follows acting leads as the roster does, and the rollcall lands on a section with troopers
// while its picker still lists the empty ones.
//
// 7CAV → 1-7 → A/1-7 → {1/A/1-7 → {1/1/A/1-7, 2/1/A/1-7}, 2/A/1-7 → 1/2/A/1-7}. Every section has a trooper.

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->platoon1 = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->platoon2 = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->company->id);
    $this->section1 = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon1->id);
    $this->section2 = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->platoon1->id);
    $this->section3 = $units->create(UnitType::Section, '1st Section', '1/2/A/1-7', $this->platoon2->id);

    foreach ([$this->section1, $this->section2, $this->section3] as $section) {
        rlPlace(Trooper::factory()->create(), $section);
    }

    $this->admin = User::factory()->admin()->create();
});

function rlPlace(Trooper $trooper, Unit $section): Trooper
{
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

/** A serving trooper whose billet commands `$unit`, and their account. */
function rlLeaderOf(Unit $unit, int $cavId): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $cavId + 10000]);
    Trooper::factory()->create(['cav_id' => $cavId, 'cav_position_id' => $cavId + 10000, 'status' => TrooperStatus::Combat]);

    return User::factory()->create(['cav_id' => $cavId]);
}

function rlActingLead(User $user, Unit $unit): void
{
    $trooper = Trooper::query()->where('cav_id', $user->cav_id)->firstOrFail();
    UnitActingLead::query()->create(['unit_id' => $unit->id, 'trooper_id' => $trooper->id, 'assigned_by' => null]);
}

describe('a person preview opens where that person would', function () {
    it('opens the roster on the section of a section leader who also holds a grant over the battalion', function () {
        $leader = rlLeaderOf($this->section2, 100);
        PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $this->battalion->id]);

        $this->actingAs($leader)->get('/roster')->assertInertia(fn ($page) => $page->where('unit.id', $this->section2->id));

        $this->actingAs($this->admin)->post('/view-as', ['kind' => 'user', 'id' => $leader->id]);
        $this->get('/roster')->assertInertia(fn ($page) => $page->where('unit.id', $this->section2->id));
    });

    it('opens the rollcall on the section of a section leader who also holds a grant over the battalion', function () {
        $leader = rlLeaderOf($this->section2, 100);
        PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $this->battalion->id]);

        $this->actingAs($leader)->get('/rollcall')->assertRedirect("/rollcall/{$this->section2->id}");

        $this->actingAs($this->admin)->post('/view-as', ['kind' => 'user', 'id' => $leader->id]);
        $this->get('/rollcall')->assertRedirect("/rollcall/{$this->section2->id}");
    });

    it('opens the roster on the unit an acting lead with no billet leads, with a grant over the company', function () {
        $trooper = Trooper::factory()->create(['cav_id' => 300, 'status' => TrooperStatus::Combat]);
        $lead = User::factory()->create(['cav_id' => $trooper->cav_id]);
        UnitActingLead::query()->create(['unit_id' => $this->section2->id, 'trooper_id' => $trooper->id, 'assigned_by' => null]);
        PortalRoleGrant::factory()->create(['user_id' => $lead->id, 'unit_id' => $this->company->id]);

        $this->actingAs($lead)->get('/roster')->assertInertia(fn ($page) => $page->where('unit.id', $this->section2->id));

        $this->actingAs($this->admin)->post('/view-as', ['kind' => 'user', 'id' => $lead->id]);
        $this->get('/roster')->assertInertia(fn ($page) => $page->where('unit.id', $this->section2->id));
        $this->get('/rollcall')->assertRedirect("/rollcall/{$this->section2->id}");
    });

    it('opens on the billet of a trooper with no account yet', function () {
        UnitCommandMapping::factory()->create(['unit_id' => $this->platoon2->id, 'cav_position_id' => 9400]);
        $trooper = Trooper::factory()->create(['cav_id' => 400, 'cav_position_id' => 9400, 'status' => TrooperStatus::Combat]);

        $this->actingAs($this->admin)->post('/view-as', ['kind' => 'trooper', 'id' => $trooper->id])->assertSessionHasNoErrors();

        $this->get('/roster')->assertInertia(fn ($page) => $page->where('unit.id', $this->platoon2->id));
        $this->get('/rollcall')->assertRedirect("/rollcall/{$this->section3->id}");
    });

    it('opens a unit preview at that unit, not at the viewer\'s own billet inside it', function () {
        $commander = rlLeaderOf($this->company, 100);
        rlActingLead($commander, $this->section2);

        $this->actingAs($commander)->post('/view-as', ['kind' => 'unit', 'id' => $this->platoon1->id]);

        $this->get('/roster')->assertInertia(fn ($page) => $page->where('unit.id', $this->platoon1->id));
    });
});

describe('the rollcall landing', function () {
    it('follows an acting lead, as the roster does', function () {
        $platoonLeader = rlLeaderOf($this->platoon1, 100);
        rlActingLead($platoonLeader, $this->section3);

        $this->actingAs($platoonLeader)->get('/rollcall')->assertRedirect("/rollcall/{$this->section3->id}");
    });

    it('skips a section with no troopers, but the picker still lists it as having none', function () {
        $empty = app(UnitRepository::class)->create(UnitType::Section, 'New Section', '0/1/A/1-7', $this->platoon1->id);
        $platoonLeader = rlLeaderOf($this->platoon1, 100);

        $this->actingAs($platoonLeader)->get('/rollcall')->assertRedirect("/rollcall/{$this->section1->id}");

        $sections = collect($this->get('/rollcall?pick=1')->viewData('page')['props']['sections'])->keyBy('id');

        expect($sections->keys()->all())->toHaveCount(3)->toContain($empty->id)
            ->and($sections[$empty->id]['progress']['strength'])->toBe(0);
    });

    it('lands on the first section when every one in scope is empty', function () {
        $units = app(UnitRepository::class);
        $platoon = $units->create(UnitType::Platoon, '3rd Platoon', '3/A/1-7', $this->company->id);
        $first = $units->create(UnitType::Section, '1st Section', '1/3/A/1-7', $platoon->id);
        $units->create(UnitType::Section, '2nd Section', '2/3/A/1-7', $platoon->id);
        $platoonLeader = rlLeaderOf($platoon, 100);

        $this->actingAs($platoonLeader)->get('/rollcall')->assertRedirect("/rollcall/{$first->id}");
        // Followed: on a Saturday before the report deadline the bare URL opens the week still due.
        $this->followingRedirects()->get("/rollcall/{$first->id}")->assertOk();
    });

    it('skips an empty section for its own leader too, when another in their scope has troopers', function () {
        $empty = app(UnitRepository::class)->create(UnitType::Section, 'New Section', '3/1/A/1-7', $this->platoon1->id);
        $leader = rlLeaderOf($empty, 100);
        PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $this->platoon1->id]);

        $this->actingAs($leader)->get('/rollcall')->assertRedirect("/rollcall/{$this->section1->id}");
    });
});
