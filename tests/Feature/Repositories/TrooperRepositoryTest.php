<?php

use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Repositories\TrooperRepository;

// AD-4: the cache is upserted keyed by `cav_id` onto a stable internal PK, never
// truncate-and-reinsert, and a departed trooper is marked inactive rather than deleted — these
// tests exercise exactly those invariants, not just that a row lands.

it('inserts a new row for an unseen cav_id', function () {
    $repo = app(TrooperRepository::class);

    $repo->upsertActive([
        ['cav_id' => 42, 'rank' => 'Sergeant', 'mos' => '11B', 'tis' => '2020-01-01', 'tig' => '2022-01-01', 'status' => TrooperStatus::Combat->value],
    ]);

    $trooper = Trooper::where('cav_id', 42)->sole();

    expect($trooper->rank)->toBe('Sergeant')
        ->and($trooper->mos)->toBe('11B')
        ->and($trooper->status)->toBe(TrooperStatus::Combat);
});

it('updates an existing row in place, leaving the internal PK unchanged', function () {
    $repo = app(TrooperRepository::class);

    $repo->upsertActive([
        ['cav_id' => 42, 'rank' => 'Private', 'mos' => '11B', 'tis' => null, 'tig' => null, 'status' => TrooperStatus::Combat->value],
    ]);
    $originalId = Trooper::where('cav_id', 42)->sole()->id;

    $repo->upsertActive([
        ['cav_id' => 42, 'rank' => 'Corporal', 'mos' => '11B', 'tis' => null, 'tig' => null, 'status' => TrooperStatus::Reserve->value],
    ]);

    $trooper = Trooper::where('cav_id', 42)->sole();

    expect(Trooper::count())->toBe(1)
        ->and($trooper->id)->toBe($originalId)
        ->and($trooper->rank)->toBe('Corporal')
        ->and($trooper->status)->toBe(TrooperStatus::Reserve);
});

it('marks every cav_id outside the active set as inactive, retaining the row', function () {
    $repo = app(TrooperRepository::class);
    $stillActive = Trooper::factory()->create(['cav_id' => 1, 'status' => TrooperStatus::Combat]);
    $departed = Trooper::factory()->create(['cav_id' => 2, 'status' => TrooperStatus::Combat]);

    $repo->markDepartedExcept([$stillActive->cav_id]);

    expect(Trooper::count())->toBe(2)
        ->and($stillActive->refresh()->status)->toBe(TrooperStatus::Combat)
        ->and($departed->refresh()->status)->toBe(TrooperStatus::Inactive);
});

it('reactivates a previously-departed trooper by cav_id rather than duplicating the row', function () {
    $repo = app(TrooperRepository::class);
    $trooper = Trooper::factory()->create(['cav_id' => 7, 'status' => TrooperStatus::Inactive]);

    $repo->upsertActive([
        ['cav_id' => 7, 'rank' => 'Sergeant', 'mos' => '11B', 'tis' => null, 'tig' => null, 'status' => TrooperStatus::Combat->value],
    ]);

    expect(Trooper::count())->toBe(1);

    $trooper->refresh();
    expect($trooper->status)->toBe(TrooperStatus::Combat);
});

it('never truncates: an empty active set marks every cached row inactive instead of deleting them', function () {
    $repo = app(TrooperRepository::class);
    Trooper::factory()->create(['cav_id' => 1, 'status' => TrooperStatus::Combat]);

    $repo->markDepartedExcept([]);

    expect(Trooper::count())->toBe(1)
        ->and(Trooper::sole()->status)->toBe(TrooperStatus::Inactive);
});

it('leaves primary_section_id untouched by an upsert — that column belongs to a different writer', function () {
    $repo = app(TrooperRepository::class);
    $section = Unit::factory()->create();
    Trooper::factory()->create(['cav_id' => 1, 'primary_section_id' => $section->id, 'status' => TrooperStatus::Combat]);

    $repo->upsertActive([
        ['cav_id' => 1, 'rank' => 'Corporal', 'mos' => '11B', 'tis' => null, 'tig' => null, 'status' => TrooperStatus::Combat->value],
    ]);

    expect(Trooper::where('cav_id', 1)->sole()->primary_section_id)->toBe($section->id);
});

it('wraps upsert and departure marking in one transaction via sync()', function () {
    $repo = app(TrooperRepository::class);
    $departed = Trooper::factory()->create(['cav_id' => 2, 'status' => TrooperStatus::Combat]);

    $repo->sync(
        [['cav_id' => 1, 'rank' => 'Sergeant', 'mos' => '11B', 'tis' => null, 'tig' => null, 'status' => TrooperStatus::Combat->value]],
        [1],
    );

    expect(Trooper::where('cav_id', 1)->sole()->status)->toBe(TrooperStatus::Combat)
        ->and($departed->refresh()->status)->toBe(TrooperStatus::Inactive);
});
