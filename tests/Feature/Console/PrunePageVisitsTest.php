<?php

use App\Models\PageVisit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

// #382: the activity log keeps 30 days; older visits are deleted by a daily scheduled command.

it('schedules portal:prune-page-visits daily', function () {
    $this->artisan('list');

    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'portal:prune-page-visits'));

    expect($event)->not->toBeNull()
        ->and($event->getExpression())->toBe('15 4 * * *')
        ->and($event->filtersPass($this->app))->toBeTrue();
});

it('deletes visits older than 30 days and keeps the rest', function () {
    Carbon::setTestNow('2026-10-28 04:15:00');
    PageVisit::factory()->count(3)->create(['visited_at' => '2026-09-28 04:14:59']);
    $edge = PageVisit::factory()->create(['visited_at' => '2026-09-28 04:15:00']);
    $recent = PageVisit::factory()->create(['visited_at' => '2026-10-27 22:00:00']);

    $this->artisan('portal:prune-page-visits')
        ->expectsOutput('Deleted 3 page visits older than 30 days.')
        ->assertSuccessful();

    expect(PageVisit::query()->orderBy('id')->pluck('id')->all())->toBe([$edge->id, $recent->id]);
});

it('says so when there is nothing to delete', function () {
    $this->artisan('portal:prune-page-visits')
        ->expectsOutput('Deleted 0 page visits older than 30 days.')
        ->assertSuccessful();
});
