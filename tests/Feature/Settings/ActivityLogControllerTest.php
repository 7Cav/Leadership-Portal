<?php

use App\Models\PageVisit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Inertia\Testing\AssertableInertia as Assert;

// #382: Settings → Activity log, the Admin-only list of the pages each user opened, filterable by user and date.

beforeEach(function () {
    $this->admin = User::factory()->serving()->admin()->create(['username' => 'Pike.S']);
});

it('requires authentication', function () {
    $this->get('/settings/activity-log')->assertRedirect(route('login'));
});

it('refuses a non-Admin with 403 (AD-6)', function () {
    $this->actingAs(User::factory()->serving()->create())->get('/settings/activity-log')->assertForbidden();
});

it('refuses an Admin previewing a leader with view as', function () {
    $regiment = app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $regiment->id])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get('/settings/activity-log')->assertForbidden();
});

it('refuses an Admin acting as someone, who is that person on the request', function () {
    $person = User::factory()->serving()->create();
    $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $person->id])->assertRedirect('/home');

    $this->actingAs($this->admin)->get('/settings/activity-log')->assertForbidden();
});

it('lists every visit newest first, with who was acted or viewed as', function () {
    $hale = User::factory()->create(['username' => 'Hale.S']);
    $older = PageVisit::factory()->create(['user_id' => $hale->id, 'url' => '/roster?unit=4', 'visited_at' => '2026-10-27 09:00:00']);
    $newer = PageVisit::factory()->create([
        'user_id' => $hale->id, 'url' => '/home', 'acting_as' => 'SGT Vasquez.R', 'viewing_as' => '1/A/1-7', 'visited_at' => '2026-10-28 14:05:09',
    ]);

    $this->actingAs($this->admin)->get('/settings/activity-log')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Settings/ActivityLog')
        ->where('retentionDays', 30)
        ->where('visits.total', 2)
        ->where('visits.data.0', [
            'id' => $newer->id, 'user' => 'Hale.S', 'url' => '/home', 'actingAs' => 'SGT Vasquez.R', 'viewingAs' => '1/A/1-7',
            'visited_at' => '2026-10-28T14:05:09+00:00',
        ])
        ->where('visits.data.1.id', $older->id)
        ->where('visits.data.1.actingAs', null)
        ->where('userOptions', [['id' => $hale->id, 'username' => 'Hale.S']]));
});

it('filters by user and by an inclusive date range', function () {
    $hale = User::factory()->create(['username' => 'Hale.S']);
    $other = User::factory()->create(['username' => 'Brandt.K']);
    PageVisit::factory()->create(['user_id' => $hale->id, 'visited_at' => '2026-10-20 23:59:59']);
    $inRange = PageVisit::factory()->create(['user_id' => $hale->id, 'visited_at' => '2026-10-21 00:00:00']);
    $lastDay = PageVisit::factory()->create(['user_id' => $hale->id, 'visited_at' => '2026-10-22 23:59:59']);
    PageVisit::factory()->create(['user_id' => $other->id, 'visited_at' => '2026-10-21 12:00:00']);

    $this->actingAs($this->admin)->get("/settings/activity-log?user_id={$hale->id}&date_from=2026-10-21&date_to=2026-10-22")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('visits.total', 2)
            ->where('visits.data.0.id', $lastDay->id)
            ->where('visits.data.1.id', $inRange->id)
            ->where('filters', ['user_id' => (string) $hale->id, 'date_from' => '2026-10-21', 'date_to' => '2026-10-22']));
});

it('refuses a date that is not a calendar date', function () {
    $this->actingAs($this->admin)->get('/settings/activity-log?date_from=now')->assertSessionHasErrors('date_from');
    $this->actingAs($this->admin)->get('/settings/activity-log?user_id=abc')->assertSessionHasErrors('user_id');
});

it('pages 50 at a time and keeps the filters on the paging links', function () {
    $hale = User::factory()->create();
    PageVisit::factory()->count(51)->create(['user_id' => $hale->id, 'visited_at' => '2026-10-21 10:00:00']);

    $this->actingAs($this->admin)->get("/settings/activity-log?user_id={$hale->id}&date_from=2026-10-21")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('visits.total', 51)
            ->has('visits.data', 50)
            ->where('visits.next_page_url', fn ($url) => str_contains($url, "user_id={$hale->id}") && str_contains($url, 'date_from=2026-10-21') && str_contains($url, 'page=2')));
});
