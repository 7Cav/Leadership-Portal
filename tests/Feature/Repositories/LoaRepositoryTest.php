<?php

use App\Models\Loa;
use App\Models\Trooper;
use App\Repositories\LoaRepository;

// 4.1: the read path the badge and resolver will use. The sync (4.2) adds the only write.

it('finds the row for a forum thread', function () {
    $loa = Loa::factory()->create(['forum_thread_id' => 7001]);

    expect(app(LoaRepository::class)->findByForumThreadId(7001)?->id)->toBe($loa->id);
});

it('returns null for a thread it has never seen', function () {
    expect(app(LoaRepository::class)->findByForumThreadId(1))->toBeNull();
});

it('lists a trooper\'s leave newest first, unreadable rows last, then by id', function () {
    $trooper = Trooper::factory()->create();
    $old = Loa::factory()->create(['trooper_id' => $trooper->id, 'starts_at' => '2026-01-05', 'ends_at' => '2026-01-10']);
    $new = Loa::factory()->create(['trooper_id' => $trooper->id, 'starts_at' => '2026-10-05', 'ends_at' => '2026-10-10']);
    $review = Loa::factory()->needsReview()->create(['trooper_id' => $trooper->id]);
    $sameDayFirst = Loa::factory()->create(['trooper_id' => $trooper->id, 'starts_at' => '2026-10-05', 'ends_at' => '2026-10-06']);

    expect(app(LoaRepository::class)->forTrooper($trooper->id)->pluck('id')->all())
        ->toBe([$sameDayFirst->id, $new->id, $old->id, $review->id]);
});

it('never returns another trooper\'s leave', function () {
    $trooper = Trooper::factory()->create();
    $mine = Loa::factory()->create(['trooper_id' => $trooper->id]);
    Loa::factory()->create();

    expect(app(LoaRepository::class)->forTrooper($trooper->id)->pluck('id')->all())->toBe([$mine->id]);
});

it('returns an empty collection for a trooper with no leave', function () {
    expect(app(LoaRepository::class)->forTrooper(Trooper::factory()->create()->id)->all())->toBe([]);
});

it('does not list a row whose username matched no trooper under any trooper', function () {
    $trooper = Trooper::factory()->create();
    Loa::factory()->needsReview()->create(['trooper_id' => null]);

    expect(app(LoaRepository::class)->forTrooper($trooper->id)->all())->toBe([]);
});
