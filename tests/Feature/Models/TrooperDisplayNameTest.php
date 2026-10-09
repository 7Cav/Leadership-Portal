<?php

use App\Models\Trooper;
use Illuminate\Support\Facades\Schema;

// Story 2.10 — 7Cav identifies a member as `RANK Lastname.I`; every screen renders the cached
// trooper through this one accessor so the fallback for a missing forum account is not
// reinvented per screen.

it('renders the short rank and forum username', function () {
    $trooper = new Trooper(['cav_id' => 42, 'rank_short' => 'SGT', 'username' => 'Vasquez.R']);

    expect($trooper->display_name)->toBe('SGT Vasquez.R');
});

it('falls back to the cav id when the trooper has no forum username', function () {
    $trooper = new Trooper(['cav_id' => 42, 'rank_short' => 'SGT', 'username' => null]);

    expect($trooper->display_name)->toBe('SGT #42')
        ->and((new Trooper(['cav_id' => 42, 'rank_short' => 'SGT', 'username' => '']))->display_name)->toBe('SGT #42');
});

it('drops the rank rather than rendering a leading space when the short rank is missing', function () {
    expect((new Trooper(['cav_id' => 42, 'rank_short' => null, 'username' => 'Vasquez.R']))->display_name)->toBe('Vasquez.R')
        ->and((new Trooper(['cav_id' => 42, 'rank_short' => null, 'username' => null]))->display_name)->toBe('#42');
});

it('has an index on forum_user_id so a portal user can be matched to their trooper', function () {
    $indexed = collect(Schema::getIndexes('troopers'))
        ->contains(fn (array $index) => $index['columns'] === ['forum_user_id']);

    expect($indexed)->toBeTrue()
        ->and(Schema::hasColumns('troopers', ['username', 'forum_user_id', 'rank_short']))->toBeTrue()
        ->and(Schema::hasColumn('troopers', 'real_name'))->toBeFalse();
});
