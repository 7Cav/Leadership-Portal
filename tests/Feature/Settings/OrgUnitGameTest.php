<?php

use App\Models\AuditLogEntry;
use App\Models\Game;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitGame;
use App\Repositories\UnitRepository;
use Inertia\Testing\AssertableInertia as Assert;

// Settings, Org structure: an Admin sets the game a unit plays from the Games list, and every unit under it plays the
// same game unless it has one of its own (policy, 2026-10-29). The team label stays free text beside it.

/** @return array<int, array<string, mixed>> the page's units by id */
function ougUnits($response): array
{
    return collect($response->viewData('page')['props']['units'])->keyBy('id')->all();
}

function ougSetGame(object $test, Unit $unit, int|string|null $gameId, ?User $as = null)
{
    return $test->actingAs($as ?? $test->admin)->patch("/settings/org-structure/{$unit->id}/game", ['game_id' => $gameId]);
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '2nd Battalion', '2-7', $this->regiment->id);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/2-7', $this->battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/2-7', $this->company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/2/A/2-7', $this->platoon->id);
    $this->other = $units->create(UnitType::Company, 'Bravo Company', 'B/2-7', $this->battalion->id);

    $this->admin = User::factory()->admin()->create();
});

describe('setting a unit\'s game', function () {
    it('lets an Admin set and clear it, audited, and writes nothing when it is unchanged', function () {
        ougSetGame($this, $this->platoon, game('wardogs')->id)
            ->assertRedirect('/settings/org-structure')
            ->assertSessionHasNoErrors();

        expect($this->platoon->fresh()->game_id)->toBe(game('wardogs')->id)
            ->and(AuditLogEntry::where('action', 'unit.game_set')->count())->toBe(1);

        // Unchanged: nothing written, nothing logged.
        ougSetGame($this, $this->platoon, game('wardogs')->id)->assertSessionHasNoErrors();
        expect(AuditLogEntry::where('action', 'unit.game_set')->count())->toBe(1);

        // None (inherits) clears it.
        ougSetGame($this, $this->platoon, null)->assertSessionHasNoErrors();
        expect($this->platoon->fresh()->game_id)->toBeNull()
            ->and(AuditLogEntry::where('action', 'unit.game_set')->count())->toBe(2);

        // Clearing a unit that has none is unchanged too.
        ougSetGame($this, $this->platoon, null);
        expect(AuditLogEntry::where('action', 'unit.game_set')->count())->toBe(2);
    });

    it('leaves the team label alone, and the label leaves the game alone', function () {
        $this->actingAs($this->admin)->patch("/settings/org-structure/{$this->platoon->id}/label", ['label' => 'Team Bravo']);
        ougSetGame($this, $this->platoon, game('arma')->id);

        expect($this->platoon->fresh()->label)->toBe('Team Bravo')
            ->and($this->platoon->fresh()->game_id)->toBe(game('arma')->id);
    });

    it('refuses anyone but an Admin, changing nothing', function () {
        ougSetGame($this, $this->platoon, game('wardogs')->id, User::factory()->serving()->create())->assertForbidden();
        ougSetGame($this, $this->platoon, game('wardogs')->id, User::factory()->commanding($this->platoon)->create())->assertForbidden();

        expect($this->platoon->fresh()->game_id)->toBeNull()
            ->and(AuditLogEntry::query()->where('action', 'unit.game_set')->count())->toBe(0);
    });

    it('refuses an Admin previewing someone with view as: the preview is read-only', function () {
        $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->company->id])->assertSessionHasNoErrors();

        $this->patch("/settings/org-structure/{$this->platoon->id}/game", ['game_id' => game('wardogs')->id])->assertForbidden();

        expect($this->platoon->fresh()->game_id)->toBeNull()
            ->and(AuditLogEntry::query()->where('action', 'unit.game_set')->count())->toBe(0);
    });

    it('refuses a game that does not exist or is retired, as a form error, changing nothing', function () {
        ougSetGame($this, $this->platoon, 999999)
            ->assertSessionHasErrors(['game_id' => 'That game no longer exists. Reload the page and choose another.']);

        $squad = Game::factory()->retired()->create(['name' => 'Squad']);
        ougSetGame($this, $this->platoon, $squad->id)
            ->assertSessionHasErrors(['game_id' => 'Squad is retired, so no unit can be set to play it.']);

        ougSetGame($this, $this->platoon, 'wardogs')->assertSessionHasErrors('game_id');

        expect($this->platoon->fresh()->game_id)->toBeNull()
            ->and(AuditLogEntry::query()->where('action', 'unit.game_set')->count())->toBe(0);
    });

    it('keeps a game that was retired after it was set, and still lets it be cleared', function () {
        ougSetGame($this, $this->platoon, game('wardogs')->id);
        game('wardogs')->forceFill(['retired_at' => now()])->save();

        // Saving the same game again is unchanged, not a new pick of a retired game.
        ougSetGame($this, $this->platoon, game('wardogs')->id)->assertSessionHasNoErrors();
        expect($this->platoon->fresh()->game_id)->toBe(game('wardogs')->id);

        ougSetGame($this, $this->platoon, null)->assertSessionHasNoErrors();
        expect($this->platoon->fresh()->game_id)->toBeNull();
    });

    it('answers a unit that does not exist with 404, for an Admin only', function () {
        $this->actingAs($this->admin)->patch('/settings/org-structure/999999/game', ['game_id' => null])->assertNotFound();
        $this->actingAs(User::factory()->serving()->create())->patch('/settings/org-structure/999999/game', ['game_id' => null])->assertForbidden();
    });
});

describe('the game a unit plays', function () {
    it('is its own game, else the nearest ancestor\'s, else none', function () {
        $units = app(UnitRepository::class);

        // None anywhere on the chain.
        expect($units->gameOf($this->section->id))->toBeNull();

        // An ancestor's.
        $units->setGame($this->battalion, game('hll')->id);
        $inherited = $units->gameOf($this->section->id);
        expect($inherited->gameId)->toBe(game('hll')->id)
            ->and($inherited->inherited())->toBeTrue()
            ->and($inherited->from->id)->toBe($this->battalion->id);

        // The nearest of two ancestors.
        $units->setGame($this->platoon, game('wardogs')->id);
        $nearest = $units->gameOf($this->section->id);
        expect($nearest->gameId)->toBe(game('wardogs')->id)
            ->and($nearest->from->id)->toBe($this->platoon->id);

        // Its own, over every ancestor's.
        $units->setGame($this->section, game('arma')->id);
        $own = $units->gameOf($this->section->id);
        expect($own->gameId)->toBe(game('arma')->id)
            ->and($own->inherited())->toBeFalse()
            ->and($own->from)->toBeNull();

        // A unit off that chain inherits only from its own ancestors.
        expect($units->gameOf($this->other->id)->gameId)->toBe(game('hll')->id)
            ->and($units->gameOf($this->regiment->id))->toBeNull()
            ->and($units->gameOf(999999))->toBeNull();
    });

    it('is worked out the same way over the whole tree at once', function () {
        $units = app(UnitRepository::class);
        $units->setGame($this->battalion, game('hll')->id);
        $units->setGame($this->platoon, game('wardogs')->id);

        $games = UnitGame::resolve($units->tree());

        expect($games)->not->toHaveKey($this->regiment->id)
            ->and($games[$this->battalion->id]->from)->toBeNull()
            ->and($games[$this->company->id]->gameId)->toBe(game('hll')->id)
            ->and($games[$this->company->id]->from->id)->toBe($this->battalion->id)
            ->and($games[$this->section->id]->gameId)->toBe(game('wardogs')->id)
            ->and($games[$this->section->id]->from->id)->toBe($this->platoon->id)
            ->and($games[$this->other->id]->gameId)->toBe(game('hll')->id);
    });

    it('is sent on the org page for each unit with where it comes from, and the Games list for the picker', function () {
        $units = app(UnitRepository::class);
        $units->setGame($this->battalion, game('hll')->id);
        $units->setGame($this->platoon, game('wardogs')->id);
        game('wardogs')->forceFill(['retired_at' => now()])->save();

        $response = $this->actingAs(User::factory()->serving()->create())->get('/settings/org-structure')->assertOk();
        $page = ougUnits($response);

        expect($page[$this->regiment->id]['game'])->toBeNull()
            ->and($page[$this->regiment->id]['game_id'])->toBeNull()
            ->and($page[$this->battalion->id]['game_id'])->toBe(game('hll')->id)
            ->and($page[$this->battalion->id]['game'])->toBe(['id' => game('hll')->id, 'name' => 'Hell Let Loose (WW2)', 'retired' => false, 'from' => null])
            ->and($page[$this->company->id]['game_id'])->toBeNull()
            ->and($page[$this->company->id]['game'])->toBe(['id' => game('hll')->id, 'name' => 'Hell Let Loose (WW2)', 'retired' => false, 'from' => '2-7'])
            // A retired game a unit still has is shown, marked retired, on it and below it.
            ->and($page[$this->platoon->id]['game'])->toBe(['id' => game('wardogs')->id, 'name' => 'WARDOGS', 'retired' => true, 'from' => null])
            ->and($page[$this->section->id]['game'])->toBe(['id' => game('wardogs')->id, 'name' => 'WARDOGS', 'retired' => true, 'from' => '2/A/2-7']);

        $response->assertInertia(fn (Assert $page) => $page
            ->has('games', 4)
            ->where('games.0', ['id' => game('hll')->id, 'name' => 'Hell Let Loose (WW2)', 'retired' => false])
            ->where('games', fn ($games) => collect($games)->firstWhere('name', 'WARDOGS')['retired'] === true));
    });

    it('stays on the unit when it moves: what the unit inherits follows its new parent', function () {
        $units = app(UnitRepository::class);
        $units->setGame($this->company, game('wardogs')->id);
        $units->setGame($this->other, game('arma')->id);

        $this->actingAs($this->admin)->patch("/settings/org-structure/{$this->platoon->id}", ['name' => '2nd Platoon', 'callsign' => '2/A/2-7', 'parent_id' => $this->other->id])
            ->assertSessionHasNoErrors();

        expect($units->gameOf($this->section->id)->gameId)->toBe(game('arma')->id)
            ->and($this->company->fresh()->game_id)->toBe(game('wardogs')->id);
    });
});
