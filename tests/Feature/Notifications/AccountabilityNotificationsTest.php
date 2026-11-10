<?php

use App\Commands\Units\AccountabilityRules;
use App\Commands\Units\SetAccountabilityOptions;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\UnitType;
use App\Models\User;
use App\Notifications\AccountabilityChanged;
use App\Repositories\UnitRepository;
use Illuminate\Notifications\DatabaseNotification;

require_once __DIR__.'/../StaffPractices/fixture.php';

// A battalion's HQ staff are told in the bell when someone above them changes the accountability options of a unit in
// their battalion; their own changes, the battalion's own set and other battalions tell them nothing. Each user only
// ever reads and marks their own notifications.

beforeEach(function () {
    $this->tree = staffPracticeTree();
    $u = $this->tree['units'];
    $this->admin = $this->tree['users']['admin'];

    $staff = Trooper::factory()->create(['primary_section_id' => $u['battalion']->id, 'username' => 'Stone.A']);
    $this->bnStaff = User::factory()->create(['cav_id' => $staff->cav_id, 'username' => 'Stone.A']);
    $second = Trooper::factory()->create(['primary_section_id' => $u['battalion']->id, 'username' => 'Reyes.D']);
    $this->bnStaff2 = User::factory()->create(['cav_id' => $second->cav_id, 'username' => 'Reyes.D']);
    // Placed on the battalion but inactive: not its HQ staff any more.
    $gone = Trooper::factory()->create(['primary_section_id' => $u['battalion']->id, 'status' => TrooperStatus::Inactive]);
    $this->gone = User::factory()->create(['cav_id' => $gone->cav_id]);

    $other = app(UnitRepository::class)->create(UnitType::Battalion, '2nd Battalion', '2-7', $u['regiment']->id);
    $otherStaff = Trooper::factory()->create(['primary_section_id' => $other->id]);
    $this->otherBnStaff = User::factory()->create(['cav_id' => $otherStaff->cav_id]);

    $this->set = fn (User $actor, $unit, ?array $options) => app(SetAccountabilityOptions::class)->handle($actor, $unit->id, $options);
});

function bellOf(User $user)
{
    return DatabaseNotification::query()->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->id)->get();
}

it('tells the battalion\'s serving HQ staff when an Admin changes a unit under it', function () {
    $platoon = $this->tree['units']['platoon'];

    ($this->set)($this->admin, $platoon, [AccountabilityRules::SP_OWN_UNIT, AccountabilityRules::FTX]);

    $row = bellOf($this->bnStaff)->sole();
    expect($row->type)->toBe(AccountabilityChanged::class)
        ->and($row->data['message'])->toBe("Hale.S changed 1/A/1-7's accountability options to: Section practice in own unit, FTX.")
        ->and($row->data['url'])->toBe("/roster/{$platoon->id}?view=admin")
        ->and($row->read_at)->toBeNull()
        ->and(bellOf($this->bnStaff2))->toHaveCount(1)
        ->and(bellOf($this->gone))->toHaveCount(0)
        ->and(bellOf($this->otherBnStaff))->toHaveCount(0)
        ->and(bellOf($this->admin))->toHaveCount(0);

    // Back to the default says so; a change that changes nothing tells nobody.
    ($this->set)($this->admin, $platoon, null);
    ($this->set)($this->admin, $platoon, null);
    expect(bellOf($this->bnStaff))->toHaveCount(2)
        ->and(bellOf($this->bnStaff)->pluck('data.message'))->toContain("Hale.S set 1/A/1-7's accountability options back to the default.");
});

it('tells nobody when the battalion\'s own HQ staff change a unit, or when the battalion\'s own set changes', function () {
    $u = $this->tree['units'];

    ($this->set)($this->bnStaff, $u['company'], [AccountabilityRules::SP_OWN_UNIT]);
    ($this->set)($this->admin, $u['battalion'], [AccountabilityRules::SP_OWN_UNIT, AccountabilityRules::FTX]);

    expect(DatabaseNotification::query()->count())->toBe(0);
});

it('tells nobody when the Admin making the change is on that battalion\'s HQ staff', function () {
    $staffAdmin = User::factory()->admin()->create(['cav_id' => Trooper::factory()->create(['primary_section_id' => $this->tree['units']['battalion']->id])->cav_id]);

    ($this->set)($staffAdmin, $this->tree['units']['host'], [AccountabilityRules::SP_OWN_UNIT]);

    expect(DatabaseNotification::query()->count())->toBe(0);
});

it('tells only the battalion the unit is in', function () {
    $u = $this->tree['units'];
    $units = app(UnitRepository::class);
    $other = $units->tree()->firstWhere('callsign', '2-7');
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/2-7', $other->id);

    ($this->set)($this->admin, $company, [AccountabilityRules::FTX]);

    expect(bellOf($this->otherBnStaff))->toHaveCount(1)
        ->and(bellOf($this->bnStaff))->toHaveCount(0);
});

it('lists, counts and marks read only the signed-in user\'s own notifications', function () {
    ($this->set)($this->admin, $this->tree['units']['platoon'], [AccountabilityRules::FTX]);
    ($this->set)($this->admin, $this->tree['units']['company'], [AccountabilityRules::FTX]);
    $mine = bellOf($this->bnStaff);
    $theirs = bellOf($this->bnStaff2)->first();

    $list = $this->actingAs($this->bnStaff)->getJson('/notifications')->assertOk();
    expect($list->json('unread'))->toBe(2)
        ->and($list->json('notifications'))->toHaveCount(2)
        ->and(collect($list->json('notifications'))->pluck('id')->all())->not->toContain($theirs->id)
        ->and($list->json('notifications.0'))->toHaveKeys(['id', 'message', 'url', 'read', 'createdAt']);

    $this->actingAs($this->bnStaff)->get('/home')->assertInertia(fn ($page) => $page->where('notificationsUnread', 2));

    // Someone else's id is a 404, and leaves theirs unread.
    $this->actingAs($this->bnStaff)->postJson("/notifications/{$theirs->id}/read")->assertNotFound();
    $this->actingAs($this->bnStaff)->postJson('/notifications/not-a-real-id/read')->assertNotFound();
    expect($theirs->fresh()->read_at)->toBeNull();

    $this->actingAs($this->bnStaff)->postJson("/notifications/{$mine->first()->id}/read")->assertOk()->assertJson(['unread' => 1]);
    expect($mine->first()->fresh()->read_at)->not->toBeNull();

    $this->actingAs($this->bnStaff)->postJson('/notifications/read-all')->assertOk()->assertJson(['unread' => 0]);
    expect(bellOf($this->bnStaff)->whereNull('read_at'))->toHaveCount(0)
        ->and($theirs->fresh()->read_at)->toBeNull();
});

it('keeps the bell to signed-in users', function () {
    $this->getJson('/notifications')->assertUnauthorized();
    $this->postJson('/notifications/read-all')->assertUnauthorized();
});
