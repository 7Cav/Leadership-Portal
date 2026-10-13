<?php

use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;

// The tree's "N troopers" is the number placed in the unit's sections, rolled up its branches.

function countsTree(): array
{
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', null, $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', null, $battalion->id);
    $platoon = $repo->create(UnitType::Platoon, '1st Platoon', null, $company->id);
    $sectionA = $repo->create(UnitType::Section, '1st Section', null, $platoon->id);
    $sectionB = $repo->create(UnitType::Section, '2nd Section', null, $platoon->id);
    $otherBattalion = $repo->create(UnitType::Battalion, '2nd Battalion', null, $regiment->id);

    return compact('regiment', 'battalion', 'company', 'platoon', 'sectionA', 'sectionB', 'otherBattalion');
}

function treeCounts($response): array
{
    return collect($response->viewData('page')['props']['units'])->pluck('trooper_count', 'id')->all();
}

it('counts the troopers in each section and rolls them up every branch', function () {
    $tree = countsTree();
    Trooper::factory()->count(2)->create(['primary_section_id' => $tree['sectionA']->id]);
    Trooper::factory()->create(['primary_section_id' => $tree['sectionB']->id]);
    Trooper::factory()->create(['primary_section_id' => null]);

    $counts = treeCounts($this->actingAs(User::factory()->serving()->create())->get('/settings/org-structure'));

    expect($counts[$tree['sectionA']->id])->toBe(2)
        ->and($counts[$tree['sectionB']->id])->toBe(1)
        ->and($counts[$tree['platoon']->id])->toBe(3)
        ->and($counts[$tree['company']->id])->toBe(3)
        ->and($counts[$tree['battalion']->id])->toBe(3)
        ->and($counts[$tree['regiment']->id])->toBe(3)
        ->and($counts[$tree['otherBattalion']->id])->toBe(0);
});

it('leaves out a departed trooper but keeps one on LOA', function () {
    $tree = countsTree();
    Trooper::factory()->create(['primary_section_id' => $tree['sectionA']->id, 'status' => TrooperStatus::Inactive]);
    Trooper::factory()->create(['primary_section_id' => $tree['sectionA']->id, 'status' => TrooperStatus::Eloa]);

    $counts = treeCounts($this->actingAs(User::factory()->serving()->create())->get('/settings/org-structure'));

    expect($counts[$tree['sectionA']->id])->toBe(1);
});

it('shows a trooper in the section the moment an Admin places them', function () {
    $admin = User::factory()->admin()->create();
    $tree = countsTree();
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);

    $before = treeCounts($this->actingAs($admin)->get('/settings/org-structure'));
    expect($before[$tree['sectionA']->id])->toBe(0);

    $this->post("/settings/org-structure/troopers/{$trooper->id}/placement", ['section_id' => $tree['sectionA']->id, 'reason' => 'Confirmed.']);

    $after = treeCounts($this->get('/settings/org-structure'));
    expect($after[$tree['sectionA']->id])->toBe(1)
        ->and($after[$tree['regiment']->id])->toBe(1);
});

it('counts troopers per section in the repository, only for sections that have any', function () {
    $tree = countsTree();
    Trooper::factory()->count(3)->create(['primary_section_id' => $tree['sectionA']->id]);
    Trooper::factory()->create(['primary_section_id' => null]);

    expect(app(TrooperRepository::class)->countsBySection()->all())->toBe([$tree['sectionA']->id => 3]);
});
