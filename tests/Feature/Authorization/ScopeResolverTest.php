<?php

use App\Authorization\ScopeResolver;
use App\Models\PortalRoleGrant;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

// AD-8: the resolver's load-bearing behaviour is the *set* of unit ids it hands back, not
// which method produced it — every scenario here is one leader's real question, "which units
// can I see", answered end to end through the resolver rather than by poking its collaborators.

it('resolves the full tree for an Admin, regardless of grants', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);

    // Holding a grant on a single, narrow unit must not narrow an Admin's scope down to it —
    // the admin short-circuit has to win outright, not merge with the grant union.
    $admin = User::factory()->admin()->create();
    PortalRoleGrant::factory()->create(['user_id' => $admin->id, 'unit_id' => $battalion->id]);

    $scope = app(ScopeResolver::class)->resolve($admin);

    expect($scope->all())->toEqualCanonicalizing([$regiment->id, $battalion->id]);
});

it('resolves the empty set for a user with no admin flag, no grant and no billet', function () {
    $repo = app(UnitRepository::class);
    $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);

    $user = User::factory()->create();

    $scope = app(ScopeResolver::class)->resolve($user);

    expect($scope->all())->toBe([]);
});

it('resolves a staff user with no line billet from their grant\'s subtree, never scopeless', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/2-7', $battalion->id);
    $otherBattalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);

    $staff = User::factory()->create();
    PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $battalion->id]);

    $scope = app(ScopeResolver::class)->resolve($staff);

    expect($scope->all())->toEqualCanonicalizing([$battalion->id, $company->id])
        ->and($scope->all())->not->toContain($regiment->id, $otherBattalion->id);
});

it('unions the subtrees of every grant a dual-hatted user holds', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalionA = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $battalionB = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);

    $staff = User::factory()->create();
    PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $battalionA->id]);
    PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $battalionB->id]);

    $scope = app(ScopeResolver::class)->resolve($staff);

    expect($scope->all())->toEqualCanonicalizing([$battalionA->id, $battalionB->id])
        ->and($scope->all())->not->toContain($regiment->id);
});

it('does not double-count a unit reachable through two overlapping grants', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/2-7', $battalion->id);

    $staff = User::factory()->create();
    PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $battalion->id]);
    PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $company->id]);

    $scope = app(ScopeResolver::class)->resolve($staff);

    expect($scope->all())->toEqualCanonicalizing([$battalion->id, $company->id]);
});

it('never lets one user\'s grant leak into another user\'s scope', function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);

    $grantee = User::factory()->create();
    $bystander = User::factory()->create();
    PortalRoleGrant::factory()->create(['user_id' => $grantee->id, 'unit_id' => $battalion->id]);

    $scope = app(ScopeResolver::class)->resolve($bystander);

    expect($scope->all())->toBe([]);
});
