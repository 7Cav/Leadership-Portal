<?php

use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Repositories\TrooperRepository;

// Story 4.2: a leave thread names its subject by username. Usernames are unique in the Cav, and some differ
// only by an extra initial (Perry.J, Perry.JW, Perry.JA), so the match is the whole name, never a prefix.

it('matches the whole username, so one initial is never mistaken for another', function () {
    $j = Trooper::factory()->create(['username' => 'Perry.J']);
    $jw = Trooper::factory()->create(['username' => 'Perry.JW']);
    $ja = Trooper::factory()->create(['username' => 'Perry.JA']);

    $found = app(TrooperRepository::class)->idsByUsername(['Perry.J']);

    expect($found)->toBe(['perry.j' => [$j->id]])
        ->and(app(TrooperRepository::class)->idsByUsername(['Perry.JW']))->toBe(['perry.jw' => [$jw->id]])
        ->and(app(TrooperRepository::class)->idsByUsername(['Perry.JA']))->toBe(['perry.ja' => [$ja->id]]);
});

it('never matches by prefix or by a longer name', function () {
    Trooper::factory()->create(['username' => 'Perry.JW']);

    expect(app(TrooperRepository::class)->idsByUsername(['Perry.J']))->toBe([])
        ->and(app(TrooperRepository::class)->idsByUsername(['Perry']))->toBe([])
        ->and(app(TrooperRepository::class)->idsByUsername(['Perry.JWX']))->toBe([]);
});

it('ignores case', function () {
    $trooper = Trooper::factory()->create(['username' => 'Lasso.J']);

    expect(app(TrooperRepository::class)->idsByUsername(['LASSO.J', 'lasso.j']))->toBe(['lasso.j' => [$trooper->id]]);
});

it('looks up several at once, leaving out the ones with no trooper', function () {
    $a = Trooper::factory()->create(['username' => 'Hart.A']);
    $b = Trooper::factory()->create(['username' => 'Lane.M']);

    expect(app(TrooperRepository::class)->idsByUsername(['Hart.A', 'Nobody.N', 'Lane.M']))
        ->toBe(['hart.a' => [$a->id], 'lane.m' => [$b->id]]);
});

it('lists every trooper when more than one carries the name, so the caller can refuse to guess', function () {
    $first = Trooper::factory()->create(['username' => 'Twin.T']);
    $second = Trooper::factory()->create(['username' => 'twin.t']);

    expect(app(TrooperRepository::class)->idsByUsername(['Twin.T']))->toBe(['twin.t' => [$first->id, $second->id]]);
});

it('finds a departed trooper too, since an old leave belongs to them', function () {
    $gone = Trooper::factory()->create(['username' => 'Gone.G', 'status' => TrooperStatus::Inactive]);

    expect(app(TrooperRepository::class)->idsByUsername(['Gone.G']))->toBe(['gone.g' => [$gone->id]]);
});

it('ignores a trooper with no username, and an empty request', function () {
    Trooper::factory()->create(['username' => null]);

    expect(app(TrooperRepository::class)->idsByUsername(['x']))->toBe([])
        ->and(app(TrooperRepository::class)->idsByUsername([]))->toBe([]);
});

it('finds troopers by forum user id, listing every one that shares an id and leaving unknown ids out', function () {
    $one = Trooper::factory()->create(['forum_user_id' => 501]);
    $twinA = Trooper::factory()->create(['forum_user_id' => 502]);
    $twinB = Trooper::factory()->create(['forum_user_id' => 502]);

    expect(app(TrooperRepository::class)->idsByForumUserId([501, 502, 503, 501]))
        ->toBe([501 => [$one->id], 502 => [$twinA->id, $twinB->id]])
        ->and(app(TrooperRepository::class)->idsByForumUserId([]))->toBe([]);
});
