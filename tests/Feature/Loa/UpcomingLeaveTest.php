<?php

use App\Loa\UpcomingLeave;
use App\Models\Loa;
use App\Models\LoaType;
use App\Models\Trooper;
use Carbon\CarbonImmutable;

// The "Leave from" chip: a cleanly read leave starting in the Cav week after the one asked about, read from the one
// LOA definition. The week asked about runs Saturday 03Oct26 to Friday 09Oct26; the next one opens Saturday 10Oct26.

beforeEach(function () {
    $this->week = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
});

function upcomingFor(array $troopers, CarbonImmutable $week): array
{
    return app(UpcomingLeave::class)->startingAfter(array_map(fn (Trooper $t) => $t->id, $troopers), $week);
}

it('gives the first day of a leave that starts next week, on its first or last day', function () {
    $saturday = Trooper::factory()->create();
    $friday = Trooper::factory()->create();
    $eloa = Trooper::factory()->create();
    Loa::factory()->create(['trooper_id' => $saturday->id, 'starts_at' => '2026-10-10', 'ends_at' => '2026-10-20']);
    Loa::factory()->create(['trooper_id' => $friday->id, 'starts_at' => '2026-10-16', 'ends_at' => '2026-10-18']);
    Loa::factory()->openEnded()->create(['trooper_id' => $eloa->id, 'type' => LoaType::Eloa, 'starts_at' => '2026-10-12']);

    expect(upcomingFor([$saturday, $friday, $eloa], $this->week))->toBe([
        $saturday->id => '2026-10-10',
        $friday->id => '2026-10-16',
        $eloa->id => '2026-10-12',
    ]);
});

it('leaves out a leave already running, one starting later, one that needs review and a trooper with none', function () {
    $running = Trooper::factory()->create();
    $later = Trooper::factory()->create();
    $review = Trooper::factory()->create();
    $none = Trooper::factory()->create();
    Loa::factory()->create(['trooper_id' => $running->id, 'starts_at' => '2026-10-07', 'ends_at' => '2026-10-14']);
    Loa::factory()->create(['trooper_id' => $later->id, 'starts_at' => '2026-10-17', 'ends_at' => '2026-10-20']);
    Loa::factory()->needsReview()->create(['trooper_id' => $review->id, 'posted_at' => '2026-10-09 12:00:00']);

    expect(upcomingFor([$running, $later, $review, $none], $this->week))->toBe([]);
});

it('refuses a week that does not open on a Saturday', function () {
    app(UpcomingLeave::class)->startingAfter([1], CarbonImmutable::parse('2026-10-05 00:00:00', 'UTC'));
})->throws(InvalidArgumentException::class);
