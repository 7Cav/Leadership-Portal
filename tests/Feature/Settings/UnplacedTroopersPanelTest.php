<?php

use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;

// Story 2.6: the Settings org-structure page lists the troopers the sync could not place.

it('gives an Admin the troopers the sync could not place, with what is needed to recognise them', function () {
    $admin = User::factory()->admin()->create();
    $section = Unit::factory()->create();
    Trooper::factory()->create([
        'cav_id' => 3099, 'username' => 'Hollis.D', 'rank' => 'Private', 'mos' => '11B',
        'cav_position_id' => 412, 'billet' => 'Rifleman, 2nd Platoon', 'primary_section_id' => null,
    ]);
    Trooper::factory()->create(['primary_section_id' => $section->id]);

    $this->actingAs($admin)->get('/settings/org-structure')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('unplacedTroopers', 1)
            ->where('unplacedTroopers.0.cav_id', 3099)
            ->where('unplacedTroopers.0.username', 'Hollis.D')
            ->where('unplacedTroopers.0.rank', 'Private')
            ->where('unplacedTroopers.0.mos', '11B')
            ->where('unplacedTroopers.0.billet', 'Rifleman, 2nd Platoon')
            ->where('unplacedTroopers.0.position_id', 412));
});

it('sends an empty list, not an error, when every trooper is placed', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/settings/org-structure')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('unplacedTroopers', []));
});

it('does not send the list to anyone but an Admin', function () {
    Trooper::factory()->create(['primary_section_id' => null]);

    $this->actingAs(User::factory()->create())->get('/settings/org-structure')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('unplacedTroopers', null));
});

it('withholds the list from an Admin who is previewing a unit, as it would be for that leader', function () {
    $admin = User::factory()->admin()->create();
    $unit = app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    Trooper::factory()->create(['primary_section_id' => null]);

    $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $unit->id]);

    $this->get('/settings/org-structure')
        ->assertInertia(fn ($page) => $page->where('unplacedTroopers', null));
});

it('still shows the last good cache when the latest roster sync failed', function () {
    $admin = User::factory()->admin()->create();
    Trooper::factory()->create(['primary_section_id' => null]);
    SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Failure, 'error' => 'Cav unreachable']);

    $this->actingAs($admin)->get('/settings/org-structure')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('unplacedTroopers', 1));
});

it('drops a trooper from the list once they are placed', function () {
    $admin = User::factory()->admin()->create();
    $section = Unit::factory()->create();
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);

    $this->actingAs($admin)->get('/settings/org-structure')
        ->assertInertia(fn ($page) => $page->has('unplacedTroopers', 1));

    app(TrooperRepository::class)->updatePrimarySections([$trooper->cav_id => $section->id]);

    $this->get('/settings/org-structure')
        ->assertInertia(fn ($page) => $page->has('unplacedTroopers', 0));
});
