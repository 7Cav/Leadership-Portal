<?php

use App\Models\AuditLogEntry;
use App\Models\Game;
use App\Models\User;

// A game's abbreviation: the short code on a battalion's game chip in Org structure. 2 to 6 uppercase letters or digits,
// unique, kept by an Admin on Settings, Games and audited with the game.

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

function abbrGame(array $override = []): array
{
    return ['name' => 'Squad', 'id_kinds' => ['EOS ID'], 'interface' => 'battlemetrics', ...$override];
}

it('gives the games the migrations create their known codes', function () {
    expect(Game::query()->orderBy('id')->pluck('abbreviation', 'slug')->all())
        ->toBe(['hll' => 'HLL', 'arma' => 'ARMA', 'wardogs' => 'WD', 'hll-vietnam' => 'HLLV']);
});

it('makes one from the name when a new game is added without one', function () {
    $this->actingAs($this->admin)->post('/settings/games', abbrGame())->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/settings/games', abbrGame(['name' => 'Ready Or Not']))->assertSessionHasNoErrors();
    // Taken already: a digit keeps it unique.
    $this->actingAs($this->admin)->post('/settings/games', abbrGame(['name' => 'Hidden Lake Lodge']))->assertSessionHasNoErrors();

    expect(Game::query()->where('name', 'Squad')->value('abbreviation'))->toBe('SQD')
        ->and(Game::query()->where('name', 'Ready Or Not')->value('abbreviation'))->toBe('RON')
        ->and(Game::query()->where('name', 'Hidden Lake Lodge')->value('abbreviation'))->toBe('HLL2');
});

it('takes one given when the game is added, and changes it on edit, audited', function () {
    $this->actingAs($this->admin)->post('/settings/games', abbrGame(['abbreviation' => 'SQ44']))->assertSessionHasNoErrors();
    $game = Game::query()->where('name', 'Squad')->sole();
    expect($game->abbreviation)->toBe('SQ44');

    $this->actingAs($this->admin)->patch("/settings/games/{$game->id}", abbrGame(['abbreviation' => 'SQD']))->assertSessionHasNoErrors();

    expect($game->fresh()->abbreviation)->toBe('SQD')
        ->and(AuditLogEntry::query()->orderBy('id')->pluck('action')->all())->toBe(['game.added', 'game.updated']);
});

it('keeps the abbreviation when an edit leaves it blank, and logs nothing for no change', function () {
    $hll = game('hll');

    $this->actingAs($this->admin)->patch("/settings/games/{$hll->id}", ['name' => $hll->name, 'abbreviation' => '', 'id_kinds' => $hll->id_kinds, 'interface' => 'crcon'])
        ->assertSessionHasNoErrors();

    expect($hll->fresh()->abbreviation)->toBe('HLL')
        ->and(AuditLogEntry::query()->count())->toBe(0);
});

it('refuses an abbreviation that is not 2 to 6 uppercase letters or digits, or that another game has', function (string $abbreviation) {
    $this->actingAs($this->admin)->post('/settings/games', abbrGame(['abbreviation' => $abbreviation]))->assertSessionHasErrors('abbreviation');

    expect(Game::query()->where('name', 'Squad')->exists())->toBeFalse()
        ->and(AuditLogEntry::query()->count())->toBe(0);
})->with(['one letter' => ['S'], 'seven' => ['SQUADXX'], 'lowercase' => ['sqd'], 'a space' => ['SQ D'], 'a dash' => ['SQ-D'], 'taken' => ['HLLV']]);

it('lets a game keep its own abbreviation on edit, but not take another game\'s', function () {
    $hll = game('hll');
    $payload = ['name' => $hll->name, 'id_kinds' => $hll->id_kinds, 'interface' => 'crcon'];

    $this->actingAs($this->admin)->patch("/settings/games/{$hll->id}", [...$payload, 'abbreviation' => 'HLL'])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->patch("/settings/games/{$hll->id}", [...$payload, 'abbreviation' => 'ARMA'])->assertSessionHasErrors('abbreviation');

    expect($hll->fresh()->abbreviation)->toBe('HLL');
});

it('refuses anyone but an Admin', function () {
    $hll = game('hll');

    $this->actingAs(User::factory()->serving()->create())
        ->patch("/settings/games/{$hll->id}", ['name' => $hll->name, 'abbreviation' => 'XX', 'id_kinds' => $hll->id_kinds, 'interface' => 'crcon'])
        ->assertForbidden();

    expect($hll->fresh()->abbreviation)->toBe('HLL');
});
