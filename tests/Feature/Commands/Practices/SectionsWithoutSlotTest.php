<?php

use App\Commands\Practices\SectionsWithoutSlot;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\SectionPracticeSlotRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// The warning for sections with no practice slot (#392): which sections count, and who is told where.

beforeEach(function () {
    $this->platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'path' => '930/']);
    $this->platoon->forceFill(['path' => "{$this->platoon->id}/"])->save();
    $this->section = slotSection($this->platoon, '1/1/B');
    $this->sibling = slotSection($this->platoon, '2/1/B');
    // The platoon plays Hell Let Loose: Vietnam; both sections inherit it.
    $this->platoon->forceFill(['game_id' => game('hll-vietnam')->id])->save();
});

function slotSection(Unit $platoon, string $callsign, UnitKind $kind = UnitKind::Line): Unit
{
    $section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $platoon->id, 'callsign' => $callsign]);
    $section->forceFill(['path' => "{$platoon->id}/{$section->id}/", 'kind' => $kind])->save();

    return $section;
}

function placeIn(Unit $unit, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $unit->id])->save();

    return $trooper;
}

function withoutSlot(iterable $ids): array
{
    return app(SectionsWithoutSlot::class)->among($ids);
}

it('lists a section that plays a game and has troopers but no slot, in tree order', function () {
    placeIn($this->section);
    placeIn($this->sibling);

    expect(withoutSlot([$this->platoon->id, $this->section->id, $this->sibling->id]))->toBe([
        ['id' => $this->section->id, 'designation' => '1/1/B'],
        ['id' => $this->sibling->id, 'designation' => '2/1/B'],
    ]);

    app(SectionPracticeSlotRepository::class)->set($this->section->id, 4, 120);

    expect(array_column(withoutSlot([$this->section->id, $this->sibling->id]), 'id'))->toBe([$this->sibling->id]);
});

it('leaves out a section with no game, no one serving, or that is a group', function () {
    // Nobody serving: an inactive trooper does not count.
    placeIn($this->section, ['status' => TrooperStatus::Inactive]);
    expect(withoutSlot([$this->section->id]))->toBe([]);

    placeIn($this->section);
    $this->platoon->forceFill(['game_id' => null])->save();
    expect(withoutSlot([$this->section->id]))->toBe([]);

    $this->platoon->forceFill(['game_id' => game('hll-vietnam')->id])->save();
    $eloa = slotSection($this->platoon, 'ELOA', UnitKind::Eloa);
    placeIn($eloa);
    expect(array_column(withoutSlot([$this->section->id, $eloa->id]), 'id'))->toBe([$this->section->id]);
});

it('reads a fixed number of queries however many sections there are', function () {
    $count = function (int $sections) {
        $ids = [];
        foreach (range(1, $sections) as $i) {
            $section = slotSection($this->platoon, "x{$i}");
            placeIn($section);
            $ids[] = $section->id;
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        withoutSlot($ids);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(2))->toBe($count(10));
});

it('warns a Section Leader on Home about their own section only', function () {
    placeIn($this->section);
    placeIn($this->sibling);
    $leader = User::factory()->commanding($this->section)->create();

    $this->actingAs($leader)->get('/home')->assertInertia(fn (Assert $page) => $page
        ->where('sectionsWithoutSlot', [['id' => $this->section->id, 'designation' => '1/1/B']]));
});

it('warns a Platoon Leader about every section, and someone outside the unit about none', function () {
    placeIn($this->section);
    placeIn($this->sibling);

    $this->actingAs(User::factory()->commanding($this->platoon)->create())->get('/home')
        ->assertInertia(fn (Assert $page) => $page->has('sectionsWithoutSlot', 2));
    $this->actingAs(User::factory()->serving()->create())->get('/home')
        ->assertInertia(fn (Assert $page) => $page->where('sectionsWithoutSlot', []));
});

it('flags the roll call, and the roster banner and section rows', function () {
    placeIn($this->section);
    placeIn($this->sibling);
    app(SectionPracticeSlotRepository::class)->set($this->sibling->id, 4, 120);
    $leader = User::factory()->commanding($this->platoon)->create();
    $week = WeekOf::start(CarbonImmutable::now())->toDateString();

    $this->actingAs($leader)->get("/rollcall/{$this->section->id}?week={$week}")
        ->assertInertia(fn (Assert $page) => $page->where('practiceSlotMissing', true));
    $this->actingAs($leader)->get("/rollcall/{$this->sibling->id}?week={$week}")
        ->assertInertia(fn (Assert $page) => $page->where('practiceSlotMissing', false));

    $this->actingAs($leader)->get("/roster/{$this->platoon->id}")->assertInertia(function (Assert $page) {
        $props = $page->toArray()['props'];
        $rows = collect($props['units'])->keyBy('id');
        expect($props['sectionsWithoutSlot'])->toBe([['id' => $this->section->id, 'designation' => '1/1/B']])
            ->and($rows[$this->section->id]['practiceSlotMissing'])->toBeTrue()
            ->and($rows[$this->sibling->id]['practiceSlotMissing'])->toBeFalse()
            ->and($rows[$this->platoon->id]['practiceSlotMissing'])->toBeFalse();
    });
});
