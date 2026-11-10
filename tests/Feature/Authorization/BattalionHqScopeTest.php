<?php

use App\Authorization\ScopeResolver;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

// Battalion HQ staff, a serving trooper placed directly on a battalion node, read that battalion so they can open its
// rosters and set each unit's accountability options there. Read only, and only while serving.

beforeEach(function () {
    $units = app(UnitRepository::class);
    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/2-7', $this->battalion->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/2-7', $company->id);
    $other = $units->create(UnitType::Battalion, '3rd Battalion', '3-7', $regiment->id);
    $this->otherCompany = $units->create(UnitType::Company, 'Charlie Company', 'C/3-7', $other->id);

    $this->trooper = Trooper::factory()->create(['username' => 'Staff.B']);
    $this->trooper->forceFill(['primary_section_id' => $this->battalion->id])->save();
    $this->user = User::factory()->create(['cav_id' => $this->trooper->cav_id, 'username' => 'Staff.B']);
});

it('reads its own battalion and nothing else, without write scope', function () {
    $scope = app(ScopeResolver::class);

    expect($scope->resolve($this->user)->all())->toContain($this->battalion->id, $this->section->id)
        ->not->toContain($this->otherCompany->id)
        ->and($scope->realScopes(collect([$this->user]))->get($this->user->id)->all())->toContain($this->section->id)
        ->and($scope->writeScope($this->user)->all())->toBe([]);
});

it('opens a roster in its battalion and not one outside it', function () {
    $this->actingAs($this->user)->get("/roster/{$this->section->id}")->assertOk();
    $this->actingAs($this->user)->get("/roster/{$this->otherCompany->id}")->assertForbidden();
});

it('loses the battalion once no longer serving', function () {
    $this->trooper->forceFill(['status' => TrooperStatus::Inactive])->save();

    expect(app(ScopeResolver::class)->resolve($this->user)->all())->not->toContain($this->battalion->id);
});
