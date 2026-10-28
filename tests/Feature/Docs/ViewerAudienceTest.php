<?php

use App\Authorization\ViewAs;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Docs\Audience;
use App\Docs\ViewerAudience;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitActingLeadRepository;
use App\Repositories\UnitRepository;

// #408: which guides a viewer reads follows their real authority through the ScopeResolver (AD-8), and a view-as
// preview answers for whoever it shows (AD-9). A staff grant reads an echelon but never makes someone a leader.

beforeEach(function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $regiment->id);
    $this->platoon = $repo->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $this->section = $repo->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
});

function docAudienceOf(User $user): Audience
{
    return app(ViewerAudience::class)->of($user);
}

function docServingUser(int $cavId, ?int $positionId = null): User
{
    Trooper::factory()->create(['cav_id' => $cavId, 'cav_position_id' => $positionId, 'status' => TrooperStatus::Combat]);

    return User::factory()->create(['cav_id' => $cavId]);
}

function docLeaderOf(Unit $unit, int $cavId): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $cavId + 10000]);

    return docServingUser($cavId, $cavId + 10000);
}

function docGrantHolder(Unit $unit, int $cavId): User
{
    $user = docServingUser($cavId);
    PortalRoleGrant::factory()->create(['user_id' => $user->id, 'unit_id' => $unit->id]);

    return $user;
}

function docPreviewAs(User $viewer, ViewAsKind $kind, int $id): void
{
    app(ViewAs::class)->enter($viewer, new ViewAsTarget($kind, $id));
}

it('reads an Admin as admin, a leader by billet as leader, and anyone else as everyone', function () {
    expect(docAudienceOf(User::factory()->admin()->create()))->toBe(Audience::Admin)
        ->and(docAudienceOf(docLeaderOf($this->section, 101)))->toBe(Audience::Leader)
        ->and(docAudienceOf(docLeaderOf($this->platoon, 102)))->toBe(Audience::Leader)
        ->and(docAudienceOf(docServingUser(103)))->toBe(Audience::Everyone)
        ->and(docAudienceOf(User::factory()->create(['cav_id' => null])))->toBe(Audience::Everyone);
});

it('reads an acting lead as a leader, the same as a billet', function () {
    $user = docServingUser(201);
    app(UnitActingLeadRepository::class)->assign($this->platoon->id, Trooper::where('cav_id', 201)->value('id'), null);

    expect(docAudienceOf($user))->toBe(Audience::Leader);
});

it('never reads a staff grant as a leadership billet, however wide the grant', function () {
    expect(docAudienceOf(docGrantHolder(Unit::where('callsign', '7CAV')->sole(), 301)))->toBe(Audience::Everyone);
});

it('reads a departed leader whose billet is still mapped as everyone', function () {
    $leader = docLeaderOf($this->section, 401);
    Trooper::where('cav_id', 401)->update(['status' => TrooperStatus::Inactive]);

    expect(docAudienceOf($leader))->toBe(Audience::Everyone);
});

it('follows an Admin\'s preview of a person: leader, grant holder, trooper or Admin', function () {
    $admin = User::factory()->admin()->create();

    docPreviewAs($admin, ViewAsKind::User, docLeaderOf($this->section, 501)->id);
    expect(docAudienceOf($admin))->toBe(Audience::Leader);

    docPreviewAs($admin, ViewAsKind::User, docGrantHolder($this->platoon, 502)->id);
    expect(docAudienceOf($admin))->toBe(Audience::Everyone);

    docPreviewAs($admin, ViewAsKind::User, docServingUser(503)->id);
    expect(docAudienceOf($admin))->toBe(Audience::Everyone);

    docPreviewAs($admin, ViewAsKind::User, User::factory()->admin()->create()->id);
    expect(docAudienceOf($admin))->toBe(Audience::Admin);
});

it('reads an Admin\'s unit preview as that unit\'s leader', function () {
    $admin = User::factory()->admin()->create();
    docPreviewAs($admin, ViewAsKind::Unit, $this->section->id);

    expect(docAudienceOf($admin))->toBe(Audience::Leader);
});

it('keeps a leader previewing a unit below them a leader', function () {
    $platoonLeader = docLeaderOf($this->platoon, 601);
    docPreviewAs($platoonLeader, ViewAsKind::Unit, $this->section->id);

    expect(docAudienceOf($platoonLeader))->toBe(Audience::Leader);
});

it('goes back to the real audience once the preview ends', function () {
    $admin = User::factory()->admin()->create();
    docPreviewAs($admin, ViewAsKind::User, docServingUser(701)->id);
    expect(docAudienceOf($admin))->toBe(Audience::Everyone);

    app(ViewAs::class)->exit();

    expect(docAudienceOf($admin))->toBe(Audience::Admin);
});

it('nests the audiences: an Admin reads all three, a leader two, everyone one', function () {
    expect(collect(Audience::cases())->filter(fn (Audience $guide) => Audience::Admin->reads($guide))->all())->toHaveCount(3)
        ->and(Audience::Leader->reads(Audience::Leader))->toBeTrue()
        ->and(Audience::Leader->reads(Audience::Everyone))->toBeTrue()
        ->and(Audience::Leader->reads(Audience::Admin))->toBeFalse()
        ->and(Audience::Everyone->reads(Audience::Leader))->toBeFalse()
        ->and(Audience::Everyone->reads(Audience::Everyone))->toBeTrue();
});
