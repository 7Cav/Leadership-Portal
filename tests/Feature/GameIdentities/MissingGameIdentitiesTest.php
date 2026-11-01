<?php

use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Rollcall\MissingGameIdentities;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// The alert for troopers with no identity linked for the game their unit plays: who counts, and who is told.

beforeEach(function () {
    $this->platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'path' => '920/']);
    $this->platoon->forceFill(['path' => "{$this->platoon->id}/"])->save();
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id, 'callsign' => '1/B']);
    $this->section->forceFill(['path' => "{$this->platoon->id}/{$this->section->id}/"])->save();
    $this->sibling = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id]);
    $this->sibling->forceFill(['path' => "{$this->platoon->id}/{$this->sibling->id}/"])->save();
    // The platoon plays Hell Let Loose: Vietnam; both sections inherit it.
    $this->platoon->forceFill(['game_id' => game('hll-vietnam')->id])->save();
});

function trooperIn(Unit $unit, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $unit->id])->save();

    return $trooper;
}

function linkOn(Trooper $trooper, string $slug, array $attributes = []): void
{
    TrooperGameIdentity::factory()->create(['trooper_id' => $trooper->id, 'game_id' => game($slug)->id, ...$attributes]);
}

it('counts a trooper as missing only without an identity on the game their unit plays, inherited or own', function () {
    $none = trooperIn($this->section);
    $steam = trooperIn($this->section);
    $epic = trooperIn($this->section);
    $wrongGame = trooperIn($this->section);
    linkOn($steam, 'hll-vietnam');
    // Any of the game's ID kinds counts.
    linkOn($epic, 'hll-vietnam', ['id_kind' => 'Epic ID', 'platform_id' => str_repeat('a', 32)]);
    linkOn($wrongGame, 'hll');

    $missing = app(MissingGameIdentities::class)->placedIn([$this->section->id]);

    expect(array_keys($missing))->toEqualCanonicalizing([$none->id, $wrongGame->id])
        ->and($missing[$none->id]->slug)->toBe('hll-vietnam');
});

it("uses a unit's own game over the one above it", function () {
    $this->sibling->forceFill(['game_id' => game('arma')->id])->save();
    $trooper = trooperIn($this->sibling);
    linkOn($trooper, 'hll-vietnam');

    expect(app(MissingGameIdentities::class)->placedIn([$this->sibling->id])[$trooper->id]->slug)->toBe('arma');
});

it('leaves out departed troopers, units with no game, retired games and groups', function () {
    trooperIn($this->section, ['status' => TrooperStatus::Inactive]);
    $this->platoon->forceFill(['game_id' => null])->save();
    trooperIn($this->section);

    expect(app(MissingGameIdentities::class)->placedIn([$this->section->id]))->toBe([]);

    $this->platoon->forceFill(['game_id' => game('hll-vietnam')->id])->save();
    game('hll-vietnam')->forceFill(['retired_at' => now()])->save();
    expect(app(MissingGameIdentities::class)->placedIn([$this->section->id]))->toBe([]);

    game('hll-vietnam')->forceFill(['retired_at' => null])->save();
    $group = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id]);
    $group->forceFill(['path' => "{$this->platoon->id}/{$group->id}/", 'kind' => UnitKind::Eloa])->save();
    trooperIn($group);

    expect(app(MissingGameIdentities::class)->placedIn([$group->id]))->toBe([]);
});

it('reads a fixed number of queries however many troopers there are', function () {
    $count = function (int $troopers) {
        foreach (range(1, $troopers) as $i) {
            trooperIn($this->section);
        }
        $placed = Trooper::query()->get();
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(MissingGameIdentities::class)->forTroopers($placed);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(2))->toBe($count(10));
});

it('alerts a Section Leader on Home about their own section only', function () {
    $mine = trooperIn($this->section, ['username' => 'Ortega.M', 'rank_short' => 'SGT']);
    trooperIn($this->sibling);
    $leader = User::factory()->commanding($this->section)->create();

    $this->actingAs($leader)->get('/home')->assertInertia(fn (Assert $page) => $page
        ->has('missingIdentities', 1)
        ->where('missingIdentities.0.game', game('hll-vietnam')->name)
        ->where('missingIdentities.0.count', 1)
        ->where('missingIdentities.0.troopers.0', ['id' => $mine->id, 'name' => 'SGT Ortega.M']));
});

it('alerts a Platoon Leader about the whole platoon', function () {
    trooperIn($this->section);
    trooperIn($this->sibling);
    $leader = User::factory()->commanding($this->platoon)->create();

    $this->actingAs($leader)->get('/home')->assertInertia(fn (Assert $page) => $page->where('missingIdentities.0.count', 2));
});

it('tells someone outside the unit nothing', function () {
    trooperIn($this->section);

    $this->actingAs(User::factory()->serving()->create())->get('/home')
        ->assertInertia(fn (Assert $page) => $page->where('missingIdentities', []));
});

it('flags the trooper row on the roll call and the roster', function () {
    $missing = trooperIn($this->section);
    $linked = trooperIn($this->section);
    linkOn($linked, 'hll-vietnam');
    $leader = User::factory()->commanding($this->platoon)->create();
    $name = game('hll-vietnam')->name;

    $this->actingAs($leader)->get("/rollcall/{$this->section->id}?week=".WeekOf::start(CarbonImmutable::now())->toDateString())->assertInertia(function (Assert $page) use ($missing, $name) {
        $rows = collect($page->toArray()['props']['troopers'])->keyBy('id');
        expect($rows[$missing->id]['missingGame'])->toBe($name)
            ->and($rows->except($missing->id)->pluck('missingGame')->filter()->all())->toBe([]);
    });

    $this->actingAs($leader)->get("/roster/{$this->platoon->id}")->assertInertia(function (Assert $page) use ($missing, $linked, $name) {
        $rows = collect($page->toArray()['props']['troopers'])->keyBy('id');
        expect($rows[$missing->id]['missingGame'])->toBe($name)
            ->and($rows[$linked->id]['missingGame'])->toBeNull();
    });
});
