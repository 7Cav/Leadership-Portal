<?php

use App\Models\AuditLogEntry;
use App\Models\Game;
use App\Models\GamePlayerDismissal;
use App\Models\PracticeCapturePlayer;
use App\Models\SectionPractice;
use App\Models\TelemetryInterface;
use App\Models\TrooperGameIdentity;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// #388: an Admin keeps the list of games and picks how each one's attendance is read (AD-3, AD-6, AD-16).

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

function addGame(array $override = []): array
{
    return ['name' => 'Squad', 'id_label' => 'EOS ID', 'interface' => 'battlemetrics', ...$override];
}

it('lists every game with its id label, interface and state, and the interfaces an Admin can pick', function () {
    TrooperGameIdentity::factory()->create(['game_id' => game('hll')->id]);
    Game::factory()->retired()->create(['slug' => 'foxhole', 'name' => 'Foxhole']);

    $this->actingAs($this->admin)->get('/settings/games')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Settings/Games')
        ->where('isAdmin', true)
        ->has('games', 4)
        ->where('games.0', ['id' => game('hll')->id, 'slug' => 'hll', 'name' => 'Hell Let Loose', 'idLabel' => 'Steam ID', 'interface' => 'crcon', 'retired' => false, 'inUse' => true])
        ->where('games.1.inUse', false)
        ->where('games.3', fn ($game) => $game['slug'] === 'foxhole' && $game['retired'] === true)
        ->where('interfaces', [
            ['value' => 'crcon', 'label' => 'CRCON'],
            ['value' => 'battlemetrics', 'label' => 'BattleMetrics'],
            ['value' => 'nightwatch', 'label' => 'Nightwatch'],
            ['value' => 'none', 'label' => 'None'],
        ]));
});

it('shows the list to anyone signed in, with the authoring controls hidden', function () {
    $this->actingAs(User::factory()->serving()->create())->get('/settings/games')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('isAdmin', false)->has('games', 3));
});

it('adds a game with a slug made from its name, audited', function () {
    $this->actingAs($this->admin)->post('/settings/games', addGame(['name' => 'Hell Let Loose: Vietnam']))->assertRedirect('/settings/games');

    $game = Game::query()->where('name', 'Hell Let Loose: Vietnam')->sole();
    $entry = AuditLogEntry::query()->sole();

    expect($game->slug)->toBe('hell-let-loose-vietnam')
        ->and($game->id_label)->toBe('EOS ID')
        ->and($game->interface)->toBe(TelemetryInterface::BattleMetrics)
        ->and($game->isRetired())->toBeFalse()
        ->and($entry->action)->toBe('game.added')
        ->and($entry->actor_id)->toBe($this->admin->id)
        ->and($entry->target_type)->toBe('Game')
        ->and($entry->target_id)->toBe((string) $game->id);
});

it('keeps slugs unique when two names make the same one', function () {
    Game::factory()->create(['slug' => 'squad', 'name' => 'Squad (old)']);

    $this->actingAs($this->admin)->post('/settings/games', addGame(['name' => 'Squad!']))->assertRedirect();

    expect(Game::query()->where('name', 'Squad!')->value('slug'))->toBe('squad-2');
});

it('validates the name, the id label and the interface', function () {
    $this->actingAs($this->admin)->post('/settings/games', ['name' => '', 'id_label' => '', 'interface' => 'dragnet'])
        ->assertSessionHasErrors(['name', 'id_label', 'interface']);
    $this->actingAs($this->admin)->post('/settings/games', addGame(['name' => 'Hell Let Loose']))
        ->assertSessionHasErrors('name');
    $this->actingAs($this->admin)->post('/settings/games', addGame(['name' => '!!!']))
        ->assertSessionHasErrors('name');

    expect(Game::query()->count())->toBe(3)->and(AuditLogEntry::query()->count())->toBe(0);
});

it('renames a game, relabels its id and changes its interface, keeping the slug, audited', function () {
    $hll = game('hll');

    $this->actingAs($this->admin)->patch("/settings/games/{$hll->id}", ['name' => 'HLL', 'id_label' => 'Steam64', 'interface' => 'battlemetrics'])->assertRedirect();

    $hll->refresh();
    expect([$hll->slug, $hll->name, $hll->id_label, $hll->interface])->toBe(['hll', 'HLL', 'Steam64', TelemetryInterface::BattleMetrics])
        ->and(AuditLogEntry::query()->sole()->action)->toBe('game.updated');
});

it('records nothing when a game is saved unchanged', function () {
    $hll = game('hll');

    $this->actingAs($this->admin)->patch("/settings/games/{$hll->id}", ['name' => 'Hell Let Loose', 'id_label' => 'Steam ID', 'interface' => 'crcon'])->assertRedirect();

    expect(AuditLogEntry::query()->count())->toBe(0);
});

it('retires a game in use and brings it back, each audited once', function () {
    $hll = game('hll');
    TrooperGameIdentity::factory()->create(['game_id' => $hll->id]);

    $this->actingAs($this->admin)->post("/settings/games/{$hll->id}/retire")->assertRedirect();
    $this->actingAs($this->admin)->post("/settings/games/{$hll->id}/retire")->assertRedirect();
    expect($hll->fresh()->isRetired())->toBeTrue();

    $this->actingAs($this->admin)->delete("/settings/games/{$hll->id}/retire")->assertRedirect();
    expect($hll->fresh()->isRetired())->toBeFalse()
        ->and(AuditLogEntry::query()->orderBy('id')->pluck('action')->all())->toBe(['game.retired', 'game.reinstated'])
        ->and(TrooperGameIdentity::query()->sole()->game_id)->toBe($hll->id);
});

it('deletes a game nothing uses, audited', function () {
    $squad = Game::factory()->create();

    $this->actingAs($this->admin)->delete("/settings/games/{$squad->id}")->assertRedirect('/settings/games');

    expect(Game::query()->whereKey($squad->id)->exists())->toBeFalse()
        ->and(AuditLogEntry::query()->sole()->action)->toBe('game.deleted');
});

it('refuses to delete a game in use by an identity, a captured player or a dismissal, and changes nothing', function (Closure $use) {
    $game = Game::factory()->create();
    $use($game, $this->admin);

    $this->actingAs($this->admin)->delete("/settings/games/{$game->id}")
        ->assertSessionHasErrors(['game' => "{$game->name} is in use, so it cannot be deleted. Retire it instead."]);

    expect(Game::query()->whereKey($game->id)->exists())->toBeTrue()
        ->and(AuditLogEntry::query()->count())->toBe(0);
})->with([
    'identity' => [fn (Game $game) => TrooperGameIdentity::factory()->create(['game_id' => $game->id])],
    'captured player' => [fn (Game $game) => PracticeCapturePlayer::query()->create(['section_practice_id' => SectionPractice::factory()->create()->id, 'game_id' => $game->id, 'player_name' => 'P', 'platform_id' => '1'])],
    'dismissal' => [fn (Game $game, User $by) => GamePlayerDismissal::query()->create(['game_id' => $game->id, 'platform_id' => '1', 'player_name' => 'P', 'dismissed_by' => $by->id])],
]);

it('answers every write from a non-Admin with 403, changing nothing', function (string $method, Closure $path, array $body) {
    $this->actingAs(User::factory()->serving()->create())->call($method, $path(), $body)->assertForbidden();

    expect(Game::query()->count())->toBe(3)
        ->and(game('hll')->name)->toBe('Hell Let Loose')
        ->and(game('hll')->isRetired())->toBeFalse()
        ->and(AuditLogEntry::query()->count())->toBe(0);
})->with([
    'add' => ['POST', fn () => '/settings/games', ['name' => 'Squad', 'id_label' => 'EOS ID', 'interface' => 'none']],
    'edit' => ['PATCH', fn () => '/settings/games/'.game('hll')->id, ['name' => 'X', 'id_label' => 'Y', 'interface' => 'none']],
    'retire' => ['POST', fn () => '/settings/games/'.game('hll')->id.'/retire', []],
    'reinstate' => ['DELETE', fn () => '/settings/games/'.game('hll')->id.'/retire', []],
    'delete' => ['DELETE', fn () => '/settings/games/'.game('hll')->id, []],
]);

it('answers a game that does not exist with 404', function () {
    $this->actingAs($this->admin)->patch('/settings/games/999999', addGame())->assertNotFound();
    $this->actingAs($this->admin)->delete('/settings/games/999999')->assertNotFound();
});

it('links an audited game change to the Games page in the audit log', function () {
    $this->actingAs($this->admin)->post('/settings/games', addGame());

    $this->actingAs($this->admin)->get('/settings/audit-log')->assertInertia(fn (Assert $page) => $page
        ->where('entries.data.0.link', ['href' => '/settings/games', 'label' => 'Squad game']));
});
