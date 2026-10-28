<?php

use App\Models\PageVisit;
use App\Models\User;
use App\Repositories\PageVisitRepository;
use Carbon\CarbonImmutable;

// #382: the Admin-only activity log of the pages people opened, kept 30 days.

function pageVisits(): PageVisitRepository
{
    return app(PageVisitRepository::class);
}

it('records a visit with its address, who was acted or viewed as, and the time', function () {
    $user = User::factory()->create();

    pageVisits()->record($user->id, '/roster?unit=4', 'SGT Vasquez.R', null, CarbonImmutable::parse('2026-10-28 12:00:00', 'UTC'));

    $visit = PageVisit::query()->sole();
    expect($visit->user_id)->toBe($user->id)
        ->and($visit->url)->toBe('/roster?unit=4')
        ->and($visit->acting_as)->toBe('SGT Vasquez.R')
        ->and($visit->viewing_as)->toBeNull()
        ->and($visit->visited_at->toDateTimeString())->toBe('2026-10-28 12:00:00');
});

it('cuts an address longer than the column', function () {
    $user = User::factory()->create();

    pageVisits()->record($user->id, '/roster?q='.str_repeat('a', 3000), null, null, now());

    expect(mb_strlen(PageVisit::query()->sole()->url))->toBe(PageVisit::MAX_URL_LENGTH);
});

it('lists visits newest first, by person and by an inclusive date range', function () {
    $alice = User::factory()->create(['username' => 'Alice.A']);
    $bob = User::factory()->create(['username' => 'Bob.B']);
    $old = PageVisit::factory()->create(['user_id' => $alice->id, 'url' => '/old', 'visited_at' => '2026-10-20 23:59:59']);
    $first = PageVisit::factory()->create(['user_id' => $alice->id, 'url' => '/first', 'visited_at' => '2026-10-21 00:00:00']);
    $last = PageVisit::factory()->create(['user_id' => $alice->id, 'url' => '/last', 'visited_at' => '2026-10-22 23:59:59']);
    $bobs = PageVisit::factory()->create(['user_id' => $bob->id, 'url' => '/bob', 'visited_at' => '2026-10-22 10:00:00']);
    PageVisit::factory()->create(['user_id' => $alice->id, 'url' => '/after', 'visited_at' => '2026-10-23 00:00:00']);

    $ids = fn ($page) => collect($page->items())->pluck('id')->all();

    expect($ids(pageVisits()->filtered(dateFrom: '2026-10-21', dateTo: '2026-10-22')))->toBe([$last->id, $bobs->id, $first->id])
        ->and($ids(pageVisits()->filtered(userId: $alice->id, dateTo: '2026-10-22')))->toBe([$last->id, $first->id, $old->id])
        ->and(pageVisits()->filtered()->total())->toBe(5)
        ->and(pageVisits()->filtered()->items()[0]->relationLoaded('user'))->toBeTrue();
});

it('orders visits in the same second by id, so paging is stable', function () {
    $user = User::factory()->create();
    $a = PageVisit::factory()->create(['user_id' => $user->id, 'visited_at' => '2026-10-22 10:00:00']);
    $b = PageVisit::factory()->create(['user_id' => $user->id, 'visited_at' => '2026-10-22 10:00:00']);

    expect(collect(pageVisits()->filtered()->items())->pluck('id')->all())->toBe([$b->id, $a->id]);
});

it('offers only the people who have a visit, by username', function () {
    $zed = User::factory()->create(['username' => 'Zed.Z']);
    $amy = User::factory()->create(['username' => 'Amy.A']);
    User::factory()->create(['username' => 'Never.N']);
    PageVisit::factory()->count(2)->create(['user_id' => $zed->id]);
    PageVisit::factory()->create(['user_id' => $amy->id]);

    expect(pageVisits()->users()->pluck('username')->all())->toBe(['Amy.A', 'Zed.Z']);
});

it('prunes every visit before the cutoff in chunks and keeps the rest', function () {
    $user = User::factory()->create();
    PageVisit::factory()->count(5)->create(['user_id' => $user->id, 'visited_at' => '2026-09-01 00:00:00']);
    $kept = PageVisit::factory()->create(['user_id' => $user->id, 'visited_at' => '2026-09-28 12:00:00']);

    $deleted = pageVisits()->pruneBefore(CarbonImmutable::parse('2026-09-28 12:00:00', 'UTC'), chunk: 2);

    expect($deleted)->toBe(5)
        ->and(PageVisit::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('deletes a person\'s visits with their account', function () {
    $user = User::factory()->create();
    PageVisit::factory()->create(['user_id' => $user->id]);

    $user->delete();

    expect(PageVisit::query()->count())->toBe(0);
});
