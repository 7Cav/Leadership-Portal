<?php

use App\Models\AuditLogEntry;
use App\Models\Game;
use App\Models\TrooperGameIdentity;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// #428: an Admin gives a game a list of ID kinds on Settings > Games, and cannot take away one an identity uses.

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->hll = game('hll');
});

function gameForm(array $override = []): array
{
    return ['name' => 'Hell Let Loose', 'id_kinds' => ['Steam ID', 'Epic ID', 'Xbox ID'], 'interface' => 'crcon', ...$override];
}

it('gives a game several ID kinds, in order, audited, and shows them on the page', function () {
    $this->actingAs($this->admin)->patch("/settings/games/{$this->hll->id}", gameForm())->assertRedirect('/settings/games');

    expect($this->hll->refresh()->id_kinds)->toBe(['Steam ID', 'Epic ID', 'Xbox ID'])
        ->and($this->hll->defaultIdKind())->toBe('Steam ID')
        ->and(AuditLogEntry::query()->sole()->action)->toBe('game.updated');

    $this->actingAs($this->admin)->get('/settings/games')
        ->assertInertia(fn (Assert $page) => $page->where('games.0.idKinds', ['Steam ID', 'Epic ID', 'Xbox ID']));
});

it('adds a game with several kinds, trimmed and as a list', function () {
    $this->actingAs($this->admin)->post('/settings/games', gameForm(['name' => 'Squad', 'id_kinds' => [3 => ' Steam ID ', 5 => 'Epic ID']]))->assertRedirect();

    expect(Game::query()->where('name', 'Squad')->sole()->id_kinds)->toBe(['Steam ID', 'Epic ID']);
});

it('treats a reorder as a change, since the first kind is the default', function () {
    $this->actingAs($this->admin)->patch("/settings/games/{$this->hll->id}", gameForm());
    $this->actingAs($this->admin)->patch("/settings/games/{$this->hll->id}", gameForm(['id_kinds' => ['Epic ID', 'Steam ID', 'Xbox ID']]))->assertRedirect();
    $this->actingAs($this->admin)->patch("/settings/games/{$this->hll->id}", gameForm(['id_kinds' => ['Epic ID', 'Steam ID', 'Xbox ID']]))->assertRedirect();

    expect($this->hll->refresh()->defaultIdKind())->toBe('Epic ID')->and(AuditLogEntry::query()->count())->toBe(2);
});

it('validates the list: at least one kind, none blank, none twice whatever the case, at most 8', function () {
    $cases = [
        [[], 'id_kinds'],
        [[''], 'id_kinds.0'],
        [['Steam ID', 'steam id'], 'id_kinds.0'],
        ['Steam ID', 'id_kinds'],
        [array_map(fn ($n) => "Kind {$n}", range(1, 9)), 'id_kinds'],
    ];

    foreach ($cases as [$kinds, $key]) {
        $this->actingAs($this->admin)->patch("/settings/games/{$this->hll->id}", gameForm(['id_kinds' => $kinds]))->assertSessionHasErrors($key);
    }

    expect($this->hll->refresh()->id_kinds)->toBe(['Steam ID'])->and(AuditLogEntry::query()->count())->toBe(0);
});

it('refuses to remove, or rename, a kind a linked identity uses, writing nothing', function () {
    $this->hll->update(['id_kinds' => ['Steam ID', 'Epic ID', 'Xbox ID']]);
    TrooperGameIdentity::factory()->create(['game_id' => $this->hll->id, 'id_kind' => 'Epic ID']);

    $this->actingAs($this->admin)->patch("/settings/games/{$this->hll->id}", gameForm(['id_kinds' => ['Steam ID', 'Xbox ID']]))
        ->assertSessionHasErrors(['id_kinds' => 'Cannot remove Epic ID: a linked identity uses it. Unlink those identities first.']);
    $this->actingAs($this->admin)->patch("/settings/games/{$this->hll->id}", gameForm(['id_kinds' => ['Steam ID', 'Epic', 'Xbox ID']]))
        ->assertSessionHasErrors('id_kinds');

    expect($this->hll->refresh()->id_kinds)->toBe(['Steam ID', 'Epic ID', 'Xbox ID'])->and(AuditLogEntry::query()->count())->toBe(0);
});

it('removes a kind nothing uses, and adds kinds while identities exist', function () {
    $this->hll->update(['id_kinds' => ['Steam ID', 'Epic ID', 'Xbox ID']]);
    TrooperGameIdentity::factory()->create(['game_id' => $this->hll->id, 'id_kind' => 'Steam ID']);

    $this->actingAs($this->admin)->patch("/settings/games/{$this->hll->id}", gameForm(['id_kinds' => ['Steam ID', 'Xbox ID', 'GOG ID']]))->assertRedirect();

    expect($this->hll->refresh()->id_kinds)->toBe(['Steam ID', 'Xbox ID', 'GOG ID']);
});
