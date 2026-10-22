<?php

use App\Integration\Cav\Data\RosterType;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\User;
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
        ->assertSessionHas('error', "Your forum account isn't linked to a serving 7th Cavalry milpac.");
    $this->assertGuest();
});

it('never ends an Admin session over roster data', function () {
    $admin = User::factory()->admin()->create(['cav_id' => Trooper::factory()->create(['status' => TrooperStatus::Inactive->value])->cav_id]);

    $this->actingAs($admin)->get(route('home'))->assertOk();
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
        ->assertSessionHas('error', "Your forum account isn't linked to a serving 7th Cavalry milpac.");
    $this->assertGuest();
});

it('answers a background fetch with a 401 when it ends the session, not a redirect it cannot follow', function () {
    $user = User::factory()->serving()->create();
    Trooper::query()->where('cav_id', $user->cav_id)->update(['status' => TrooperStatus::Inactive->value]);

    $this->actingAs($user)->getJson('/view-as/options')
        ->assertUnauthorized()
        ->assertJson(['message' => "Your forum account isn't linked to a serving 7th Cavalry milpac."]);
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
