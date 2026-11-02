<?php

use App\Models\AuditLogEntry;
use App\Models\IdentitySource;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// #174: a Steam or Epic account is linked once and connected to every active game that asks for it.

const SHARED_STEAM_ID = '76561198000000174';

beforeEach(function () {
    $platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'path' => '920/']);
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $platoon->id, 'path' => '920/921/', 'callsign' => '1/A']);
    $this->leader = User::factory()->commanding($this->section)->create();
    $this->mine = accountTrooper($this->section, ['username' => 'Ortega.M', 'rank_short' => 'SGT']);
    game('hll')->update(['id_kinds' => ['Steam ID', 'Epic ID', 'Xbox ID']]);
    game('hll-vietnam')->update(['id_kinds' => ['Steam ID', 'Epic ID']]);
    game('wardogs')->update(['id_kinds' => ['SteamID64']]);
    game('arma')->update(['id_kinds' => ['Player ID']]);
});

function accountTrooper(Unit $section, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

function linkAccount(Trooper $trooper, string $id = SHARED_STEAM_ID, string $kind = 'Steam ID', string $platform = 'hll'): array
{
    return ["/game-identities/{$trooper->id}/{$platform}/account", ['platform_id' => $id, 'id_kind' => $kind]];
}

function identityOn(Trooper $trooper, string $slug): ?TrooperGameIdentity
{
    return TrooperGameIdentity::query()->where('trooper_id', $trooper->id)->where('game_id', game($slug)->id)->first();
}

it('links a Steam ID to every active Steam game in one audited action, each row from the account', function () {
    $this->actingAs($this->leader)->post(...linkAccount($this->mine))->assertRedirect()->assertSessionHasNoErrors();

    $rows = TrooperGameIdentity::query()->with('game')->orderBy('game_id')->get();

    expect($rows->pluck('game.slug')->sort()->values()->all())->toBe(['hll', 'hll-vietnam', 'wardogs'])
        ->and($rows->pluck('source')->unique()->all())->toBe([IdentitySource::FromAccount])
        ->and(identityOn($this->mine, 'wardogs')->id_kind)->toBe('SteamID64');

    $entry = AuditLogEntry::query()->sole();
    expect($entry->action)->toBe('game_identity.account_linked')
        ->and(json_encode($entry->details))->toContain('SGT Ortega.M on WARDOGS: Source')
        ->and(json_encode($entry->details))->toContain('From account');
});

it('links an Epic ID only to the games that ask for one', function () {
    $this->actingAs($this->leader)->post(...linkAccount($this->mine, '0002105C01B748ADA8D8CF5B32E70093', 'Epic ID'))->assertSessionHasNoErrors();

    expect(TrooperGameIdentity::query()->count())->toBe(2)
        ->and(identityOn($this->mine, 'hll')->platform_id)->toBe('0002105c01b748ada8d8cf5b32e70093');
});

it('leaves out a retired game, and covers a game with no server feed', function () {
    game('hll-vietnam')->update(['retired_at' => now()]);

    $this->actingAs($this->leader)->post(...linkAccount($this->mine))->assertSessionHasNoErrors();

    expect(identityOn($this->mine, 'hll-vietnam'))->toBeNull()
        ->and(identityOn($this->mine, 'wardogs'))->not->toBeNull();
});

it('refuses the whole action when another trooper holds the ID on any covered game, naming them and the game', function () {
    $other = accountTrooper($this->section, ['username' => 'Dube.R', 'rank_short' => 'PFC']);
    TrooperGameIdentity::factory()->create(['trooper_id' => $other->id, 'game_id' => game('wardogs')->id, 'platform_id' => SHARED_STEAM_ID, 'id_kind' => 'SteamID64']);

    $this->actingAs($this->leader)->post(...linkAccount($this->mine))
        ->assertSessionHasErrors(['platform_id' => 'Already linked to PFC Dube.R on WARDOGS. Nothing was linked.']);

    expect(TrooperGameIdentity::query()->where('trooper_id', $this->mine->id)->count())->toBe(0)
        ->and(AuditLogEntry::query()->count())->toBe(0);
});

it('skips a game where the trooper already has the same ID, and refuses one where they have a different ID', function () {
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->mine->id, 'game_id' => game('hll')->id, 'platform_id' => SHARED_STEAM_ID, 'id_kind' => 'Steam ID']);

    $this->actingAs($this->leader)->post(...linkAccount($this->mine))->assertSessionHasNoErrors();

    expect(identityOn($this->mine, 'hll')->source)->toBe(IdentitySource::Typed)
        ->and(identityOn($this->mine, 'wardogs')->source)->toBe(IdentitySource::FromAccount);

    identityOn($this->mine, 'wardogs')->update(['platform_id' => '76561198000000999']);
    identityOn($this->mine, 'hll-vietnam')->delete();

    $this->actingAs($this->leader)->post(...linkAccount($this->mine))
        ->assertSessionHasErrors(['platform_id' => 'This trooper already has a different Steam ID on WARDOGS. Change or remove it first. Nothing was linked.']);

    expect(identityOn($this->mine, 'hll-vietnam'))->toBeNull()
        ->and(identityOn($this->mine, 'wardogs')->platform_id)->toBe('76561198000000999');
});

it('refuses a kind that is entered per game, and an ID without the kind\'s shape', function () {
    $this->actingAs($this->leader)->post(...linkAccount($this->mine, 'abc', 'Player ID', 'arma'))
        ->assertSessionHasErrors(['platform_id' => 'A Player ID is entered per game. Only a Steam or Epic ID can be linked to every game.']);
    $this->actingAs($this->leader)->post(...linkAccount($this->mine, '123'))->assertSessionHasErrors('platform_id');

    expect(TrooperGameIdentity::query()->count())->toBe(0);
});

it('refuses a trooper outside the leader\'s scope', function () {
    $elsewhere = accountTrooper(Unit::factory()->create(['type' => UnitType::Section, 'path' => '930/']));

    $this->actingAs($this->leader)->post(...linkAccount($elsewhere))->assertForbidden();

    expect(TrooperGameIdentity::query()->count())->toBe(0);
});

it('removes only one game\'s row when that game is removed', function () {
    $this->actingAs($this->leader)->post(...linkAccount($this->mine));

    $this->actingAs($this->leader)->delete("/game-identities/{$this->mine->id}/wardogs")->assertRedirect();

    expect(identityOn($this->mine, 'wardogs'))->toBeNull()
        ->and(identityOn($this->mine, 'hll'))->not->toBeNull()
        ->and(identityOn($this->mine, 'hll-vietnam'))->not->toBeNull();
});

it('removes the ID from every game it is linked on, however each row was made, in one audited action', function () {
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->mine->id, 'game_id' => game('hll')->id, 'platform_id' => SHARED_STEAM_ID, 'id_kind' => 'Steam ID']);
    $this->actingAs($this->leader)->post(...linkAccount($this->mine));
    $other = accountTrooper($this->section, ['username' => 'Dube.R']);
    TrooperGameIdentity::factory()->create(['trooper_id' => $other->id, 'game_id' => game('hll')->id, 'platform_id' => '76561198000000222', 'id_kind' => 'Steam ID']);

    $this->actingAs($this->leader)->delete("/game-identities/{$this->mine->id}/wardogs/account")->assertRedirect();

    expect(identityOn($this->mine, 'wardogs'))->toBeNull()
        ->and(identityOn($this->mine, 'hll-vietnam'))->toBeNull()
        ->and(identityOn($this->mine, 'hll'))->toBeNull()
        ->and(identityOn($other, 'hll'))->not->toBeNull();

    $entry = AuditLogEntry::query()->where('action', 'game_identity.account_removed')->sole();
    expect(json_encode($entry->details))->toContain('SGT Ortega.M on WARDOGS: Source');
});

it('removes nothing from every game when the ID is not a Steam or Epic ID', function () {
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->mine->id, 'game_id' => game('hll')->id, 'platform_id' => '2535412345678901', 'id_kind' => 'Xbox ID']);

    $this->actingAs($this->leader)->delete("/game-identities/{$this->mine->id}/hll/account")->assertRedirect();

    expect(identityOn($this->mine, 'hll'))->not->toBeNull()->and(AuditLogEntry::query()->count())->toBe(0);
});

it('sends each identity\'s source and each game\'s shared kinds to the page', function () {
    $this->actingAs($this->leader)->post(...linkAccount($this->mine));

    $this->actingAs($this->leader)->get('/game-identities')->assertInertia(fn (Assert $page) => $page
        ->where('troopers.0.identities.wardogs.source', 'from_account')
        ->where('platforms', fn ($platforms) => collect($platforms)->firstWhere('value', 'hll')['accounts'] == ['Steam ID' => 'steam', 'Epic ID' => 'epic']));
});

it('records a typed link as typed, and shows its source in the audit details', function () {
    $this->actingAs($this->leader)->put("/game-identities/{$this->mine->id}/hll", ['platform_id' => SHARED_STEAM_ID, 'id_kind' => 'Steam ID']);

    expect(identityOn($this->mine, 'hll')->source)->toBe(IdentitySource::Typed)
        ->and(json_encode(AuditLogEntry::query()->sole()->details))->toContain('Typed');
});

describe('backfill on Settings, Games', function () {
    beforeEach(function () {
        $this->admin = User::factory()->admin()->create();
        game('wardogs')->update(['id_kinds' => ['Player ID']]);
        TrooperGameIdentity::factory()->create(['trooper_id' => $this->mine->id, 'game_id' => game('hll')->id, 'platform_id' => SHARED_STEAM_ID, 'id_kind' => 'Steam ID']);
    });

    it('offers the backfill, with how many it would link, once a game asks for Steam', function () {
        $pending = fn () => collect($this->actingAs($this->admin)->get('/settings/games')->viewData('page')['props']['games'])->firstWhere('slug', 'wardogs');

        expect($pending()['pendingAccountLinks'])->toBe(0);

        game('wardogs')->update(['id_kinds' => ['Player ID', 'SteamID64']]);

        expect($pending()['pendingAccountLinks'])->toBe(1)->and($pending()['accounts'])->toBe(['Steam'])
            ->and(identityOn($this->mine, 'wardogs'))->toBeNull();
    });

    it('applies existing Steam links to the game, audited, skipping anyone already linked or ambiguous', function () {
        game('wardogs')->update(['id_kinds' => ['Player ID', 'SteamID64']]);
        $linked = accountTrooper($this->section, ['username' => 'Dube.R']);
        TrooperGameIdentity::factory()->create(['trooper_id' => $linked->id, 'game_id' => game('hll')->id, 'platform_id' => '76561198000000222', 'id_kind' => 'Steam ID']);
        TrooperGameIdentity::factory()->create(['trooper_id' => $linked->id, 'game_id' => game('wardogs')->id, 'platform_id' => 'w-1', 'id_kind' => 'Player ID']);
        $ambiguous = accountTrooper($this->section, ['username' => 'Kim.J']);
        TrooperGameIdentity::factory()->create(['trooper_id' => $ambiguous->id, 'game_id' => game('hll')->id, 'platform_id' => '76561198000000333', 'id_kind' => 'Steam ID']);
        TrooperGameIdentity::factory()->create(['trooper_id' => $ambiguous->id, 'game_id' => game('hll-vietnam')->id, 'platform_id' => '76561198000000444', 'id_kind' => 'Steam ID']);

        $this->actingAs($this->admin)->post('/settings/games/'.game('wardogs')->id.'/account-links')->assertRedirect('/settings/games');

        $row = identityOn($this->mine, 'wardogs');
        expect($row->platform_id)->toBe(SHARED_STEAM_ID)
            ->and($row->id_kind)->toBe('SteamID64')
            ->and($row->source)->toBe(IdentitySource::FromAccount)
            ->and(identityOn($linked, 'wardogs')->platform_id)->toBe('w-1')
            ->and(identityOn($ambiguous, 'wardogs'))->toBeNull()
            ->and(AuditLogEntry::query()->sole()->action)->toBe('game_identity.account_backfilled');

        // Nothing left to apply: a second click writes and logs nothing.
        $this->actingAs($this->admin)->post('/settings/games/'.game('wardogs')->id.'/account-links');
        expect(AuditLogEntry::query()->count())->toBe(1);
    });

    it('never changes an identity a trooper already has on the game, and skips an ID someone else holds there', function () {
        game('wardogs')->update(['id_kinds' => ['Player ID', 'SteamID64']]);
        $existing = TrooperGameIdentity::factory()->create(['trooper_id' => $this->mine->id, 'game_id' => game('wardogs')->id, 'platform_id' => '76561198000000555', 'id_kind' => 'SteamID64']);
        $before = $existing->fresh()->only(['platform_id', 'id_kind', 'source', 'updated_at']);
        $other = accountTrooper($this->section, ['username' => 'Dube.R']);
        TrooperGameIdentity::factory()->create(['trooper_id' => $other->id, 'game_id' => game('hll')->id, 'platform_id' => '76561198000000222', 'id_kind' => 'Steam ID']);
        $holder = accountTrooper($this->section, ['username' => 'Kim.J']);
        TrooperGameIdentity::factory()->create(['trooper_id' => $holder->id, 'game_id' => game('wardogs')->id, 'platform_id' => '76561198000000222', 'id_kind' => 'SteamID64']);

        $this->actingAs($this->admin)->post('/settings/games/'.game('wardogs')->id.'/account-links')->assertRedirect('/settings/games');

        expect(identityOn($this->mine, 'wardogs')->only(['platform_id', 'id_kind', 'source', 'updated_at']))->toEqual($before)
            ->and(identityOn($other, 'wardogs'))->toBeNull()
            ->and(identityOn($holder, 'wardogs')->trooper_id)->toBe($holder->id)
            ->and(TrooperGameIdentity::query()->where('game_id', game('wardogs')->id)->count())->toBe(2)
            ->and(AuditLogEntry::query()->count())->toBe(0);
    });

    it('is Admin only', function () {
        game('wardogs')->update(['id_kinds' => ['SteamID64']]);

        $this->actingAs($this->leader)->post('/settings/games/'.game('wardogs')->id.'/account-links')->assertForbidden();

        expect(identityOn($this->mine, 'wardogs'))->toBeNull();
    });
});
