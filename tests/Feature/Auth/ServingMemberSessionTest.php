<?php

use App\Authorization\ServingSession;
use App\Integration\Cav\Data\RosterType;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Sync\SyncRosterHandler;

/**
 * The sign-in gate holds for the whole session: a member the roster cache later shows departed, or a
 * non-Admin with no milpac link at all, is bounced on their next request, while Admins and linked users
 * the cache does not know are left alone.
 */
it('ends the session of a member the cache now shows departed', function () {
    $user = User::factory()->serving()->create();

    $this->actingAs($user)->get(route('home'))->assertOk();

    Trooper::query()->where('cav_id', $user->cav_id)->update(['status' => TrooperStatus::Inactive->value]);

    $this->get(route('home'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', 'Your forum account is not linked to a serving 7Cav milpac.');
    $this->assertGuest();
});

it('ends a former holder\'s session before a reassigned Cav id can confer its new billet', function () {
    $units = app(UnitRepository::class);
    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $oldSection = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $regiment->id);
    $newSection = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $regiment->id);
    $oldHolder = User::factory()->commanding($oldSection)->create();
    $newPosition = UnitCommandMapping::factory()->create(['unit_id' => $newSection->id]);

    $this->actingAs($oldHolder)->get(route('roster.index', $oldSection->id))->assertOk();

    // Roster sync upserts by cav_id, replacing the forum identity and billet in this row.
    $cached = Trooper::query()->where('cav_id', $oldHolder->cav_id)->sole();
    app(TrooperRepository::class)->sync([[
        'cav_id' => $cached->cav_id,
        'forum_user_id' => $oldHolder->forum_user_id + 1,
        'rank' => $cached->rank,
        'mos' => $cached->mos,
        'tis' => $cached->tis,
        'tig' => $cached->tig,
        'cav_position_id' => $newPosition->cav_position_id,
        'status' => TrooperStatus::Combat,
    ]], [$oldHolder->cav_id]);

    expect(app(ServingSession::class)->mustEndAmong(collect([$oldHolder]))->all())->toBe([$oldHolder->id]);

    $this->get(route('roster.index', $newSection->id))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', 'Your forum account is not linked to a serving 7Cav milpac.');
    $this->assertGuest();
});

it('never ends an Admin session over roster data', function () {
    $admin = User::factory()->admin()->serving()->create();
    Trooper::query()->where('cav_id', $admin->cav_id)->update(['status' => TrooperStatus::Inactive->value]);

    $this->actingAs($admin)->get(route('home'))->assertOk();
});

it('ends a flag Admin session when its Cav id now belongs to another forum identity', function () {
    $admin = User::factory()->admin()->serving()->create();
    $this->actingAs($admin)->get(route('home'))->assertOk();

    Trooper::query()->where('cav_id', $admin->cav_id)->update(['forum_user_id' => $admin->forum_user_id + 1]);

    $this->get(route('home'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('keeps a session whose cached trooper has no forum account recorded yet', function () {
    $user = User::factory()->serving()->create();
    Trooper::query()->where('cav_id', $user->cav_id)->update(['forum_user_id' => null]);

    $this->actingAs($user)->get(route('home'))->assertOk();
});

it('never ends an Admin session for want of a milpac link', function () {
    $this->actingAs(User::factory()->admin()->create())->get(route('home'))->assertOk();
});

it('ends the session of a non-Admin with no milpac link, such as an Admin whose flag was revoked', function () {
    $user = User::factory()->admin()->create();
    $this->actingAs($user)->get(route('home'))->assertOk();

    $user->forceFill(['is_admin' => false])->save();

    $this->get(route('home'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error', 'Your forum account is not linked to a serving 7Cav milpac.');
    $this->assertGuest();
});

it('answers a background fetch with a 401 when it ends the session, not a redirect it cannot follow', function () {
    $user = User::factory()->serving()->create();
    Trooper::query()->where('cav_id', $user->cav_id)->update(['status' => TrooperStatus::Inactive->value]);

    $this->actingAs($user)->getJson('/view-as/options')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Your forum account is not linked to a serving 7Cav milpac.']);
    $this->assertGuest();
});

it('lets through a user the cache holds no trooper for, such as one admitted live ahead of the sync', function () {
    $user = User::factory()->create(['cav_id' => 987654]);

    $this->actingAs($user)->get(route('home'))->assertOk();
});

it('judges serving standing the same way in the sign-in gate and the roster sync', function (?RosterType $roster) {
    $statusFor = new ReflectionMethod(SyncRosterHandler::class, 'statusFor');

    expect($statusFor->invoke(app(SyncRosterHandler::class), $roster)->isServing())
        ->toBe($roster?->isServing() ?? false);
})->with([...array_map(fn (RosterType $r) => [$r], RosterType::cases()), 'unrecognised' => [null]]);
