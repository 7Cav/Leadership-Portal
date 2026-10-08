<?php

use App\Authorization\ScopeResolver;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Illuminate\Support\Facades\DB;

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

// Story 1.16 — roster-derived scope (AD-7): a leader's synced primary billet, mapped by the org
// import to the unit it commands, confers that unit's subtree with no grant.

/**
 * 7CAV → 1-7 → A/1-7 → 1/A/1-7 → {1/1/A/1-7, 2/1/A/1-7}, plus a sibling battalion 2-7.
 *
 * @return array<string, Unit>
 */
function rdTree(): array
{
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
    $platoon = $repo->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $section1 = $repo->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);
    $section2 = $repo->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $platoon->id);
    $otherBattalion = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);

    return compact('regiment', 'battalion', 'company', 'platoon', 'section1', 'section2', 'otherBattalion');
}

function rdLeader(int $positionId, TrooperStatus $status = TrooperStatus::Combat): User
{
    Trooper::factory()->create(['cav_id' => 4242, 'cav_position_id' => $positionId, 'status' => $status]);

    return User::factory()->create(['cav_id' => 4242]);
}

it('derives a leader\'s scope from the unit their billet commands', function (string $commands, array $expected) {
    $tree = rdTree();
    UnitCommandMapping::factory()->create(['unit_id' => $tree[$commands]->id, 'cav_position_id' => 500]);

    $scope = app(ScopeResolver::class)->resolve(rdLeader(500));

    expect($scope->all())->toEqualCanonicalizing(array_map(fn ($key) => $tree[$key]->id, $expected));
})->with([
    'section leader → their section' => ['section1', ['section1']],
    'platoon leader → their platoon' => ['platoon', ['platoon', 'section1', 'section2']],
    'commander → their company' => ['company', ['company', 'platoon', 'section1', 'section2']],
    'battalion command → their battalion' => ['battalion', ['battalion', 'company', 'platoon', 'section1', 'section2']],
    'regimental command → the regiment' => ['regiment', ['regiment', 'battalion', 'company', 'platoon', 'section1', 'section2', 'otherBattalion']],
]);

it('unions the roster-derived subtree with explicit grants, without duplicates', function () {
    $tree = rdTree();
    UnitCommandMapping::factory()->create(['unit_id' => $tree['platoon']->id, 'cav_position_id' => 500]);
    $leader = rdLeader(500);
    PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $tree['otherBattalion']->id]);
    PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $tree['section2']->id]);

    $scope = app(ScopeResolver::class)->resolve($leader);

    expect($scope->all())->toEqualCanonicalizing([
        $tree['platoon']->id, $tree['section1']->id, $tree['section2']->id, $tree['otherBattalion']->id,
    ]);
});

it('derives nothing for a plain trooper billet or a user with no synced trooper — grants only', function () {
    $tree = rdTree();
    UnitCommandMapping::factory()->create(['unit_id' => $tree['section1']->id, 'cav_position_id' => 500]);

    $trooper = rdLeader(503);  // no command mapping for this billet
    $unsynced = User::factory()->create(['cav_id' => 9999]);
    PortalRoleGrant::factory()->create(['user_id' => $unsynced->id, 'unit_id' => $tree['section2']->id]);

    expect(app(ScopeResolver::class)->resolve($trooper)->all())->toBe([])
        ->and(app(ScopeResolver::class)->resolve($unsynced)->all())->toBe([$tree['section2']->id]);
});

it('derives nothing for a departed trooper whose billet is still mapped', function () {
    $tree = rdTree();
    UnitCommandMapping::factory()->create(['unit_id' => $tree['company']->id, 'cav_position_id' => 500]);

    expect(app(ScopeResolver::class)->resolve(rdLeader(500, TrooperStatus::Inactive))->all())->toBe([]);
});

it('derives nothing, and does not fail, once the commanded unit is deleted', function () {
    $tree = rdTree();
    UnitCommandMapping::factory()->create(['unit_id' => $tree['section1']->id, 'cav_position_id' => 500]);
    $leader = rdLeader(500);

    app(UnitRepository::class)->delete($tree['section1']);

    expect(app(ScopeResolver::class)->resolve($leader)->all())->toBe([]);
});

it('still resolves the whole tree for an Admin who also holds a leadership billet', function () {
    $tree = rdTree();
    UnitCommandMapping::factory()->create(['unit_id' => $tree['section1']->id, 'cav_position_id' => 500]);
    $leader = rdLeader(500);
    $leader->forceFill(['is_admin' => true])->save();

    expect(app(ScopeResolver::class)->resolve($leader)->all())
        ->toEqualCanonicalizing(collect($tree)->pluck('id')->all());
});

it('resolves roster-derived scope in a fixed number of queries, however large the subtree', function () {
    $tree = rdTree();
    UnitCommandMapping::factory()->create(['unit_id' => $tree['regiment']->id, 'cav_position_id' => 500]);
    $leader = rdLeader(500);

    DB::enableQueryLog();
    $scope = app(ScopeResolver::class)->resolve($leader);

    // Grants, the billet → commanded unit join, and one path-prefix subtree query.
    expect($scope)->toHaveCount(7)
        ->and(DB::getQueryLog())->toHaveCount(3);
});
