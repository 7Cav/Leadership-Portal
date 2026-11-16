<?php

use App\Models\AuditLogEntry;
use App\Models\Game;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// #751: a game's calendar tags, the text in a 7Cav calendar event's title that marks it as the game's, kept by an Admin
// on Settings > Games with the rest of the game and audited as their own action.

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

/** The form as Settings > Games sends it for a game, with what the test changes. */
function tagsForm(Game $game, array $override = []): array
{
    return ['name' => $game->name, 'abbreviation' => $game->abbreviation, 'id_kinds' => $game->id_kinds, 'interface' => $game->interface->value, ...$override];
}

it('shows each game with its calendar tags', function () {
    $this->actingAs($this->admin)->get('/settings/games')->assertInertia(fn (Assert $page) => $page
        ->where('games.0.slug', 'hll')
        ->where('games.0.eventTags', ['[HLL]']));
});

it('sets the tags with the rest of the game, trimmed, without blanks or a second of the same in any case, audited once', function () {
    $hll = game('hll');

    $this->actingAs($this->admin)->patch("/settings/games/{$hll->id}", tagsForm($hll, ['event_tags' => [' [HLL] ', 'Hell  Let Loose', '[hll]', null, '']]))
        ->assertSessionHasNoErrors();

    expect($hll->fresh()->eventTags())->toBe(['[HLL]', 'Hell Let Loose'])
        ->and(AuditLogEntry::query()->pluck('action')->all())->toBe(['game.event_tags_set'])
        // The audit log shows the tags before and after.
        ->and(AuditLogEntry::query()->sole()->details['changes'])->toBe([['field' => 'Calendar tags', 'from' => '[HLL]', 'to' => '[HLL], Hell Let Loose']]);
});

it('records nothing when the tags are the ones the game has', function () {
    $hll = game('hll');

    $this->actingAs($this->admin)->patch("/settings/games/{$hll->id}", tagsForm($hll, ['event_tags' => ['[HLL]']]))->assertSessionHasNoErrors();

    expect(AuditLogEntry::query()->count())->toBe(0);
});

it('leaves the tags as they are when the form sends none, and clears them on an empty list', function () {
    $hll = game('hll');

    $this->actingAs($this->admin)->patch("/settings/games/{$hll->id}", tagsForm($hll, ['name' => 'Hell Let Loose WW2']))->assertSessionHasNoErrors();
    expect($hll->fresh()->eventTags())->toBe(['[HLL]']);

    $this->actingAs($this->admin)->patch("/settings/games/{$hll->id}", tagsForm($hll->fresh(), ['event_tags' => []]))->assertSessionHasNoErrors();
    expect($hll->fresh()->eventTags())->toBe([])
        ->and($hll->fresh()->event_tags)->toBeNull();
});

it('takes the tags of a game when it is added', function () {
    $this->actingAs($this->admin)->post('/settings/games', ['name' => 'Squad', 'id_kinds' => ['EOS ID'], 'interface' => 'none', 'event_tags' => ['SQUAD |']])
        ->assertSessionHasNoErrors();

    expect(Game::query()->where('name', 'Squad')->sole()->eventTags())->toBe(['SQUAD |'])
        ->and(AuditLogEntry::query()->orderBy('id')->pluck('action')->all())->toBe(['game.added', 'game.event_tags_set']);
});

it('refuses a tag that is too long, or too many tags', function () {
    $hll = game('hll');

    $this->actingAs($this->admin)->patch("/settings/games/{$hll->id}", tagsForm($hll, ['event_tags' => [str_repeat('x', Game::MAX_EVENT_TAG_LENGTH + 1)]]))
        ->assertSessionHasErrors('event_tags.0');
    $this->actingAs($this->admin)->patch("/settings/games/{$hll->id}", tagsForm($hll, ['event_tags' => array_map(fn (int $n) => "tag {$n}", range(0, Game::MAX_EVENT_TAGS))]))
        ->assertSessionHasErrors('event_tags');

    expect($hll->fresh()->eventTags())->toBe(['[HLL]']);
});

it('lets only an Admin change the tags', function () {
    $hll = game('hll');

    $this->actingAs(User::factory()->serving()->create())->patch("/settings/games/{$hll->id}", tagsForm($hll, ['event_tags' => ['x']]))->assertForbidden();

    expect($hll->fresh()->eventTags())->toBe(['[HLL]']);
});

it('shows each game with its forum calendars', function () {
    $this->actingAs($this->admin)->get('/settings/games')->assertInertia(fn (Assert $page) => $page
        ->where('games.0.slug', 'hll')
        ->where('games.0.eventCalendars', ['operation' => [15], 'class' => [20], 'ftx' => [27]]));
});

it('sets the forum calendars from their links or ids, without blanks or a second of the same, audited once', function () {
    $hll = game('hll');

    $this->actingAs($this->admin)->patch("/settings/games/{$hll->id}", tagsForm($hll, ['event_calendars' => [
        'operation' => ['https://7cav.us/calendar/15/', ' 46 ', '15', null],
        'class' => ['https://7cav.us/events/calendar/20/?page=2'],
        'ftx' => [],
    ]]))->assertSessionHasNoErrors();

    expect($hll->fresh()->eventCalendars())->toBe(['operation' => [15, 46], 'class' => [20], 'ftx' => []])
        ->and(AuditLogEntry::query()->pluck('action')->all())->toBe(['game.event_calendars_set'])
        ->and(AuditLogEntry::query()->sole()->details['changes'])->toBe([['field' => 'Forum calendars', 'from' => 'operation 15; class 20; ftx 27', 'to' => 'operation 15, 46; class 20']]);
});

it('records nothing when the forum calendars are the ones the game has, and leaves them when the form sends none', function () {
    $hll = game('hll');

    $this->actingAs($this->admin)->patch("/settings/games/{$hll->id}", tagsForm($hll, ['event_calendars' => ['operation' => ['15'], 'class' => ['20'], 'ftx' => ['27']]]))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->patch("/settings/games/{$hll->id}", tagsForm($hll))->assertSessionHasNoErrors();

    expect(AuditLogEntry::query()->count())->toBe(0)
        ->and($hll->fresh()->eventCalendars())->toBe(['operation' => [15], 'class' => [20], 'ftx' => [27]]);
});

it('refuses a forum calendar that is not a calendar link or id, or an unknown kind', function () {
    $hll = game('hll');

    $this->actingAs($this->admin)->patch("/settings/games/{$hll->id}", tagsForm($hll, ['event_calendars' => ['operation' => ['https://7cav.us/events/4077/']]]))
        ->assertSessionHasErrors('event_calendars.operation.0');
    $this->actingAs($this->admin)->patch("/settings/games/{$hll->id}", tagsForm($hll, ['event_calendars' => ['misc' => ['22']]]))
        ->assertSessionHasErrors('event_calendars');

    expect($hll->fresh()->eventCalendars())->toBe(['operation' => [15], 'class' => [20], 'ftx' => [27]]);
});
