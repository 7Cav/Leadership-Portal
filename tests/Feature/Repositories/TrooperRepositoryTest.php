<?php

use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Repositories\TrooperRepository;

// AD-4: the cache is upserted keyed by `cav_id` onto a stable internal PK, never
// truncate-and-reinsert, and a departed trooper is marked inactive rather than deleted — these
// tests exercise exactly those invariants, not just that a row lands.

it('inserts a new row for an unseen cav_id, reporting it as an insert', function () {
    $repo = app(TrooperRepository::class);

    $counts = $repo->upsertActive([
        ['cav_id' => 42, 'rank' => 'Sergeant', 'mos' => '11B', 'tis' => '2020-01-01', 'tig' => '2022-01-01', 'status' => TrooperStatus::Combat->value],
    ]);

    $trooper = Trooper::where('cav_id', 42)->sole();

    expect($trooper->rank)->toBe('Sergeant')
        ->and($trooper->mos)->toBe('11B')
        ->and($trooper->status)->toBe(TrooperStatus::Combat)
        ->and($counts)->toBe(['inserted' => 1, 'updated' => 0]);
});

it('updates an existing row in place, leaving the internal PK unchanged, reporting it as an update', function () {
    $repo = app(TrooperRepository::class);

    $repo->upsertActive([
        ['cav_id' => 42, 'rank' => 'Private', 'mos' => '11B', 'tis' => null, 'tig' => null, 'status' => TrooperStatus::Combat->value],
    ]);
    $originalId = Trooper::where('cav_id', 42)->sole()->id;

    $counts = $repo->upsertActive([
        ['cav_id' => 42, 'rank' => 'Corporal', 'mos' => '11B', 'tis' => null, 'tig' => null, 'status' => TrooperStatus::Reserve->value],
    ]);

    $trooper = Trooper::where('cav_id', 42)->sole();

    expect(Trooper::count())->toBe(1)
        ->and($trooper->id)->toBe($originalId)
        ->and($trooper->rank)->toBe('Corporal')
        ->and($trooper->status)->toBe(TrooperStatus::Reserve)
        ->and($counts)->toBe(['inserted' => 0, 'updated' => 1]);
});

it('writes the forum identity and short rank, overwriting stale values and nulling an absent key', function () {
    $repo = app(TrooperRepository::class);

    $repo->upsertActive([
        ['cav_id' => 42, 'rank' => 'Sergeant', 'rank_short' => 'SGT', 'username' => 'Vasquez.R', 'forum_user_id' => 1204, 'mos' => '11B', 'tis' => null, 'tig' => null, 'status' => TrooperStatus::Combat->value],
    ]);
    $trooper = Trooper::where('cav_id', 42)->sole();

    expect($trooper->username)->toBe('Vasquez.R')
        ->and($trooper->forum_user_id)->toBe(1204)
        ->and($trooper->rank_short)->toBe('SGT');

    $repo->upsertActive([
        ['cav_id' => 42, 'rank' => 'Sergeant', 'mos' => '11B', 'tis' => null, 'tig' => null, 'status' => TrooperStatus::Combat->value],
    ]);
    $trooper->refresh();

    expect($trooper->username)->toBeNull()
        ->and($trooper->forum_user_id)->toBeNull()
        ->and($trooper->rank_short)->toBeNull();
});

it('counts several inserts and updates independently in one batch', function () {
    $repo = app(TrooperRepository::class);
    Trooper::factory()->create(['cav_id' => 1, 'status' => TrooperStatus::Combat]);
    Trooper::factory()->create(['cav_id' => 2, 'status' => TrooperStatus::Combat]);

    $counts = $repo->upsertActive([
        ['cav_id' => 1, 'rank' => 'Sergeant', 'mos' => '11B', 'tis' => null, 'tig' => null, 'status' => TrooperStatus::Combat->value],
        ['cav_id' => 2, 'rank' => 'Sergeant', 'mos' => '11B', 'tis' => null, 'tig' => null, 'status' => TrooperStatus::Combat->value],
        ['cav_id' => 3, 'rank' => 'Sergeant', 'mos' => '11B', 'tis' => null, 'tig' => null, 'status' => TrooperStatus::Combat->value],
        ['cav_id' => 4, 'rank' => 'Sergeant', 'mos' => '11B', 'tis' => null, 'tig' => null, 'status' => TrooperStatus::Combat->value],
    ]);

    expect($counts)->toBe(['inserted' => 2, 'updated' => 2]);
});

it('counts a cav_id repeated within the same batch as one insert then one update', function () {
    $repo = app(TrooperRepository::class);

    $counts = $repo->upsertActive([
        ['cav_id' => 1, 'rank' => 'Private', 'mos' => '11B', 'tis' => null, 'tig' => null, 'status' => TrooperStatus::Combat->value],
        ['cav_id' => 1, 'rank' => 'Corporal', 'mos' => '11B', 'tis' => null, 'tig' => null, 'status' => TrooperStatus::Combat->value],
    ]);

    expect(Trooper::count())->toBe(1)
        ->and(Trooper::sole()->rank)->toBe('Corporal')
        ->and($counts)->toBe(['inserted' => 1, 'updated' => 1]);
});

it('marks every cav_id outside the active set as inactive, retaining the row, reporting the count flipped', function () {
    $repo = app(TrooperRepository::class);
    $stillActive = Trooper::factory()->create(['cav_id' => 1, 'status' => TrooperStatus::Combat]);
    $departed = Trooper::factory()->create(['cav_id' => 2, 'status' => TrooperStatus::Combat]);

    $inactivated = $repo->markDepartedExcept([$stillActive->cav_id]);

    expect(Trooper::count())->toBe(2)
        ->and($stillActive->refresh()->status)->toBe(TrooperStatus::Combat)
        ->and($departed->refresh()->status)->toBe(TrooperStatus::Inactive)
        ->and($inactivated)->toBe(1);
});

it('reports zero inactivated when the active set already covers every cached row', function () {
    $repo = app(TrooperRepository::class);
    $trooper = Trooper::factory()->create(['cav_id' => 1, 'status' => TrooperStatus::Combat]);

    $inactivated = $repo->markDepartedExcept([$trooper->cav_id]);

    expect($inactivated)->toBe(0)
        ->and($trooper->refresh()->status)->toBe(TrooperStatus::Combat);
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

it('sets primary_section_id for a trooper present in the map, reporting it as updated', function () {
    $repo = app(TrooperRepository::class);
    $section = Unit::factory()->create();
    $trooper = Trooper::factory()->create(['cav_id' => 1, 'status' => TrooperStatus::Combat]);

    $updated = $repo->updatePrimarySections([1 => $section->id]);

    expect($trooper->refresh()->primary_section_id)->toBe($section->id)
        ->and($updated)->toBe(1);
});

it('leaves primary_section_id untouched for a trooper absent from the map', function () {
    $repo = app(TrooperRepository::class);
    $section = Unit::factory()->create();
    $trooper = Trooper::factory()->create(['cav_id' => 1, 'primary_section_id' => $section->id, 'status' => TrooperStatus::Combat]);

    $updated = $repo->updatePrimarySections([]);

    expect($trooper->refresh()->primary_section_id)->toBe($section->id)
        ->and($updated)->toBe(0);
});

it('moves a trooper to a new section on reorg by overwriting the previously resolved value', function () {
    $repo = app(TrooperRepository::class);
    $oldSection = Unit::factory()->create();
    $newSection = Unit::factory()->create();
    $trooper = Trooper::factory()->create(['cav_id' => 1, 'primary_section_id' => $oldSection->id, 'status' => TrooperStatus::Combat]);

    $repo->updatePrimarySections([1 => $newSection->id]);

    expect($trooper->refresh()->primary_section_id)->toBe($newSection->id);
});

it('wraps upsert and departure marking in one transaction via sync(), returning both counts', function () {
    $repo = app(TrooperRepository::class);
    $departed = Trooper::factory()->create(['cav_id' => 2, 'status' => TrooperStatus::Combat]);

    $counts = $repo->sync(
        [['cav_id' => 1, 'rank' => 'Sergeant', 'mos' => '11B', 'tis' => null, 'tig' => null, 'status' => TrooperStatus::Combat->value]],
        [1],
    );

    expect(Trooper::where('cav_id', 1)->sole()->status)->toBe(TrooperStatus::Combat)
        ->and($departed->refresh()->status)->toBe(TrooperStatus::Inactive)
        ->and($counts)->toBe(['inserted' => 1, 'updated' => 0, 'inactivated' => 1]);
});

// 3.3: the attendance writer marks a trooper only against their primary section's practice
// (AD-14, AD-18), so it asks which of the submitted troopers are actually placed there.

it('returns only the given troopers placed in the section', function () {
    $section = Unit::factory()->create();
    $other = Unit::factory()->create();
    $placed = Trooper::factory()->create(['primary_section_id' => $section->id]);
    $elsewhere = Trooper::factory()->create(['primary_section_id' => $other->id]);
    $unplaced = Trooper::factory()->create(['primary_section_id' => null]);
    Trooper::factory()->create(['primary_section_id' => $section->id]); // placed, but not asked about

    $ids = app(TrooperRepository::class)->idsPlacedIn($section->id, [$placed->id, $elsewhere->id, $unplaced->id, 999999]);

    expect($ids->all())->toBe([$placed->id]);
});

it('returns nothing for an empty list of troopers', function () {
    expect(app(TrooperRepository::class)->idsPlacedIn(Unit::factory()->create()->id, [])->all())->toBe([]);
});

// 5.1: strength's population — active troopers whose primary section sits in the subtree.
it('lists the active troopers placed anywhere in a unit\'s subtree, by id', function () {
    $platoon = Unit::factory()->create(['path' => '800/']);
    $inside = Unit::factory()->create(['path' => '800/801/']);
    $sibling = Unit::factory()->create(['path' => '8000/']);  // shares the digit prefix, not the subtree

    $place = function (Unit $unit, TrooperStatus $status = TrooperStatus::Combat) {
        $trooper = Trooper::factory()->create(['status' => $status]);
        $trooper->forceFill(['primary_section_id' => $unit->id])->save();

        return $trooper->id;
    };

    $a = $place($inside);
    $b = $place($platoon, TrooperStatus::Eloa);
    $place($inside, TrooperStatus::Inactive);
    $place($sibling);
    Trooper::factory()->create();

    expect(app(TrooperRepository::class)->assignedWithin($platoon)->all())->toBe([$a, $b]);
});

// 5.8: the same population split by section, so one query feeds a whole roll-up.
it('maps the troopers assigned in a subtree to their section, by trooper id', function () {
    $platoon = Unit::factory()->create(['path' => '800/']);
    $first = Unit::factory()->create(['path' => '800/801/']);
    $second = Unit::factory()->create(['path' => '800/802/']);
    $outside = Unit::factory()->create(['path' => '8000/']);

    $place = function (Unit $unit, TrooperStatus $status = TrooperStatus::Combat) {
        $trooper = Trooper::factory()->create(['status' => $status]);
        $trooper->forceFill(['primary_section_id' => $unit->id])->save();

        return $trooper->id;
    };

    $a = $place($second);
    $b = $place($first, TrooperStatus::Eloa);
    $place($first, TrooperStatus::Inactive);
    $place($outside);

    expect(app(TrooperRepository::class)->sectionsOfAssignedWithin($platoon)->all())
        ->toBe([$a => $second->id, $b => $first->id]);
});

it('writes the primary billet id with the synced columns, overwriting the previous one (1.16)', function () {
    $repo = app(TrooperRepository::class);
    $row = ['cav_id' => 1, 'rank' => 'Corporal', 'mos' => '11B', 'tis' => null, 'tig' => null, 'status' => TrooperStatus::Combat->value];

    $repo->upsertActive([[...$row, 'cav_position_id' => 201]]);
    expect(Trooper::where('cav_id', 1)->sole()->cav_position_id)->toBe(201);

    $repo->upsertActive([[...$row, 'cav_position_id' => 196]]);
    expect(Trooper::where('cav_id', 1)->sole()->cav_position_id)->toBe(196);

    // Cav reported no usable billet this time: the stale one is cleared, never kept.
    $repo->upsertActive([[...$row, 'cav_position_id' => null]]);
    expect(Trooper::where('cav_id', 1)->sole()->cav_position_id)->toBeNull();
});
