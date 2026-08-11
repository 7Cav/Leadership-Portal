<?php

use App\Models\PortalRoleGrant;
use App\Models\Unit;
use App\Models\User;
use App\Repositories\PortalRoleGrantRepository;

it('returns every unit a user has been granted', function () {
    $user = User::factory()->create();
    $unitA = Unit::factory()->create();
    $unitB = Unit::factory()->create();
    PortalRoleGrant::factory()->create(['user_id' => $user->id, 'unit_id' => $unitA->id]);
    PortalRoleGrant::factory()->create(['user_id' => $user->id, 'unit_id' => $unitB->id]);

    $units = app(PortalRoleGrantRepository::class)->grantedUnitsFor($user);

    expect($units->pluck('id')->all())->toEqualCanonicalizing([$unitA->id, $unitB->id]);
});

it('returns an empty collection for a user with no grants', function () {
    $user = User::factory()->create();

    $units = app(PortalRoleGrantRepository::class)->grantedUnitsFor($user);

    expect($units->all())->toBe([]);
});

it('never returns another user\'s grant', function () {
    $grantee = User::factory()->create();
    $bystander = User::factory()->create();
    $unit = Unit::factory()->create();
    PortalRoleGrant::factory()->create(['user_id' => $grantee->id, 'unit_id' => $unit->id]);

    $units = app(PortalRoleGrantRepository::class)->grantedUnitsFor($bystander);

    expect($units->all())->toBe([]);
});
