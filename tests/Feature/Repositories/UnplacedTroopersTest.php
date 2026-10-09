<?php

use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Repositories\TrooperRepository;

// Story 2.6: the cache rows the sync could not place into a section, surfaced for manual placement.

it('writes the username and billet title with the other synced columns, and clears them when Cav stops reporting them', function () {
    $repo = app(TrooperRepository::class);
    $row = ['cav_id' => 1, 'username' => 'Hollis.D', 'rank' => 'Private', 'mos' => '11B', 'tis' => '2020-01-01', 'tig' => '2022-01-01', 'status' => TrooperStatus::Combat->value, 'billet' => 'Rifleman'];

    $repo->upsertActive([$row]);
    expect(Trooper::where('cav_id', 1)->sole()->only(['username', 'billet']))->toBe(['username' => 'Hollis.D', 'billet' => 'Rifleman']);

    $repo->upsertActive([[...$row, 'username' => null, 'billet' => null]]);
    expect(Trooper::where('cav_id', 1)->sole()->only(['username', 'billet']))->toBe(['username' => null, 'billet' => null]);
});

it('lists active troopers with no primary section', function () {
    $section = Unit::factory()->create();
    $unplaced = Trooper::factory()->create(['primary_section_id' => null]);
    Trooper::factory()->create(['primary_section_id' => $section->id]);

    expect(app(TrooperRepository::class)->unplaced()->pluck('id')->all())->toBe([$unplaced->id]);
});

it('drops a trooper from the list once they are placed', function () {
    $section = Unit::factory()->create();
    $trooper = Trooper::factory()->create(['primary_section_id' => null]);
    $repo = app(TrooperRepository::class);
    expect($repo->unplaced())->toHaveCount(1);

    $repo->updatePrimarySections([$trooper->cav_id => $section->id]);

    expect($repo->unplaced())->toHaveCount(0);
});

it('leaves out a departed trooper, since nobody needs to place them', function () {
    Trooper::factory()->create(['primary_section_id' => null, 'status' => TrooperStatus::Inactive]);
    $reserve = Trooper::factory()->create(['primary_section_id' => null, 'status' => TrooperStatus::Reserve]);
    $eloa = Trooper::factory()->create(['primary_section_id' => null, 'status' => TrooperStatus::Eloa]);

    expect(app(TrooperRepository::class)->unplaced()->pluck('id')->all())->toEqualCanonicalizing([$reserve->id, $eloa->id]);
});

it('orders by username with nameless troopers last, then by cav_id, so the list is stable', function () {
    $zed = Trooper::factory()->create(['username' => 'Zed.Z', 'cav_id' => 1]);
    $nameless2 = Trooper::factory()->create(['username' => null, 'cav_id' => 20]);
    $alpha = Trooper::factory()->create(['username' => 'Alpha.A', 'cav_id' => 3]);
    $nameless1 = Trooper::factory()->create(['username' => null, 'cav_id' => 10]);

    expect(app(TrooperRepository::class)->unplaced()->pluck('id')->all())
        ->toBe([$alpha->id, $zed->id, $nameless1->id, $nameless2->id]);
});

it('returns an empty collection when everyone is placed', function () {
    expect(app(TrooperRepository::class)->unplaced()->all())->toBe([]);
});

// Story 2.8: a section's roster.

it('lists every trooper of a section, departed ones last, in a stable order', function () {
    $section = Unit::factory()->create();
    $other = Unit::factory()->create();
    $zed = Trooper::factory()->create(['username' => 'Zed.Z', 'cav_id' => 1, 'primary_section_id' => $section->id]);
    $nameless = Trooper::factory()->create(['username' => null, 'cav_id' => 2, 'primary_section_id' => $section->id]);
    $alpha = Trooper::factory()->create(['username' => 'Alpha.A', 'cav_id' => 3, 'primary_section_id' => $section->id]);
    $departed = Trooper::factory()->create(['username' => 'Aaron.A', 'cav_id' => 4, 'status' => TrooperStatus::Inactive, 'primary_section_id' => $section->id]);
    Trooper::factory()->create(['primary_section_id' => $other->id]);
    Trooper::factory()->create(['primary_section_id' => null]);

    expect(app(TrooperRepository::class)->inSection($section->id)->pluck('id')->all())
        ->toBe([$alpha->id, $zed->id, $nameless->id, $departed->id]);
});

it('returns an empty collection for a section with nobody in it', function () {
    expect(app(TrooperRepository::class)->inSection(Unit::factory()->create()->id)->all())->toBe([]);
});
