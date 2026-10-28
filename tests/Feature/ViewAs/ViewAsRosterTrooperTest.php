<?php

use App\Models\AuditLogEntry;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Inertia\Testing\AssertableInertia as Assert;

// View as and act as reach troopers who have never signed in (2026-10-28): they have no portal account, so an Admin
// previews them from their billet, and acting as them is acting as that billet.

beforeEach(function () {
    $units = app(UnitRepository::class);
    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/B/2-7', $regiment->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/B/2-7', $this->platoon->id);
    $this->other = $units->create(UnitType::Section, '2nd Section', '1/1/A/1-7', $regiment->id);

    UnitCommandMapping::factory()->create(['unit_id' => $this->platoon->id, 'cav_position_id' => 4400]);
    $this->roscoe = Trooper::factory()->create(['cav_id' => 801, 'cav_position_id' => 4400, 'username' => 'Roscoe.N', 'rank_short' => '2LT', 'status' => TrooperStatus::Combat]);
    $this->rifleman = Trooper::factory()->create(['cav_id' => 802, 'username' => 'Dube.R', 'rank_short' => 'PFC', 'status' => TrooperStatus::Combat]);
    $this->departed = Trooper::factory()->create(['cav_id' => 803, 'username' => 'Gone.X', 'status' => TrooperStatus::Inactive]);

    Trooper::factory()->create(['cav_id' => 804, 'username' => 'Signed.In', 'status' => TrooperStatus::Combat]);
    $this->member = User::factory()->create(['cav_id' => 804, 'username' => 'Signed.In']);

    $this->admin = User::factory()->admin()->create(['username' => 'Hale.S']);
});

it('offers an Admin every serving trooper with no account, marked, beside the accounts', function () {
    $people = collect($this->actingAs($this->admin)->getJson('/view-as/options')->assertOk()->json('people'));

    expect($people->firstWhere('label', '2LT Roscoe.N'))->toMatchArray(['kind' => 'trooper', 'id' => $this->roscoe->id, 'designation' => '1/B/2-7', 'account' => false])
        ->and($people->firstWhere('label', 'PFC Dube.R'))->toMatchArray(['kind' => 'trooper', 'account' => false, 'designation' => null])
        ->and($people->where('kind', 'user')->pluck('id')->all())->toContain($this->member->id)
        // Someone who has signed in is offered once, as their account; someone who has left is not offered.
        ->and($people->where('kind', 'trooper')->pluck('id')->all())->not->toContain(Trooper::where('cav_id', 804)->value('id'))
        ->and($people->where('kind', 'trooper')->pluck('id')->all())->not->toContain($this->departed->id);
});

it('previews a trooper with no account from their billet, read only, recorded against the trooper', function () {
    $this->actingAs($this->admin)->post('/view-as', ['kind' => 'trooper', 'id' => $this->roscoe->id])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('viewAs', [
        'kind' => 'trooper', 'id' => $this->roscoe->id, 'label' => '2LT Roscoe.N', 'designation' => '1/B/2-7', 'unitName' => '1st Platoon', 'admin' => false,
    ]));
    $this->actingAs($this->admin)->get("/rollcall/{$this->section->id}")->assertOk();
    $this->actingAs($this->admin)->get("/rollcall/{$this->other->id}")->assertForbidden();

    $entry = AuditLogEntry::where('action', 'view_as.started')->sole();
    expect($entry->target_type)->toBe('Trooper')->and($entry->target_id)->toBe((string) $this->roscoe->id);
});

it('refuses a preview by a non-Admin, of a departed trooper, and of one who has an account', function (Closure $as, Closure $target) {
    $viewer = $as($this);

    $this->actingAs($viewer)->post('/view-as', ['kind' => 'trooper', 'id' => $target($this)])->assertSessionHasErrors('view_as');

    expect(AuditLogEntry::count())->toBe(0);
})->with([
    'a non-Admin' => [fn ($t) => $t->member, fn ($t) => $t->roscoe->id],
    'a departed trooper' => [fn ($t) => $t->admin, fn ($t) => $t->departed->id],
    'a trooper with an account' => [fn ($t) => $t->admin, fn ($t) => Trooper::where('cav_id', 804)->value('id')],
]);

it('acts as the billet of a trooper with no account', function () {
    $this->actingAs($this->admin)->post('/act-as', ['kind' => 'trooper', 'id' => $this->roscoe->id])->assertRedirect('/home');

    $entry = AuditLogEntry::where('action', 'act_as.started')->sole();
    expect($entry->target_type)->toBe('Unit')->and($entry->target_id)->toBe((string) $this->platoon->id);

    $this->actingAs($this->admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('actingAs.kind', 'unit')->where('actingAs.label', 'Platoon Leader 1/B/2-7'));
});

it('refuses to act as a trooper with no account who holds no billet', function () {
    $this->actingAs($this->admin)->post('/act-as', ['kind' => 'trooper', 'id' => $this->rifleman->id])->assertSessionHasErrors('act_as');

    expect(AuditLogEntry::count())->toBe(0);
});
