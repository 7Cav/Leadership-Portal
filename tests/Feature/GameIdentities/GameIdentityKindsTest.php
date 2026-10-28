<?php

use App\Commands\GameIdentities\SetTrooperGameIdentity;
use App\Commands\GameIdentities\SetTrooperGameIdentityHandler;
use App\Commands\GameIdentities\UnknownIdKindException;
use App\Models\AuditLogEntry;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// #428: a game can ask for several kinds of player ID, and an identity remembers which kind it was entered as.

beforeEach(function () {
    $platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'path' => '910/']);
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $platoon->id, 'path' => '910/911/', 'callsign' => '1/A']);
    $this->leader = User::factory()->commanding($this->section)->create();
    $this->mine = kindsTrooper($this->section, ['username' => 'Ortega.M', 'rank_short' => 'SGT']);
    game('hll')->update(['id_kinds' => ['Steam ID', 'Epic ID', 'Xbox ID']]);
});

function kindsTrooper(Unit $section, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

function kindsUrl(Trooper $trooper, string $platform = 'hll'): string
{
    return "/game-identities/{$trooper->id}/{$platform}";
}

it('sends a game\'s ID kinds, and each identity\'s kind, to the page', function () {
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->mine->id, 'platform_id' => '0002105c01b748ada8d8cf5b32e70093', 'id_kind' => 'Epic ID']);

    $this->actingAs($this->leader)->get('/game-identities')->assertInertia(fn (Assert $page) => $page
        ->where('platforms.0.idKinds', ['Steam ID', 'Epic ID', 'Xbox ID'])
        ->where('troopers.0.identities.hll.idKind', 'Epic ID'));
});

it('links a trooper on the kind chosen, audited as one linking', function () {
    $this->actingAs($this->leader)
        ->put(kindsUrl($this->mine), ['player_name' => 'OrtegaCav', 'platform_id' => '0002105c01b748ada8d8cf5b32e70093', 'id_kind' => 'Epic ID'])
        ->assertRedirect();

    expect(TrooperGameIdentity::query()->sole()->id_kind)->toBe('Epic ID')
        ->and(AuditLogEntry::query()->sole()->action)->toBe('game_identity.linked');
});

it('takes the game\'s first kind when none is sent, so a one-kind game behaves as before', function () {
    $this->actingAs($this->leader)->put(kindsUrl($this->mine, 'arma'), ['player_name' => 'A', 'platform_id' => '7656'])->assertRedirect();

    expect(TrooperGameIdentity::query()->sole()->id_kind)->toBe('Player ID');
});

it('refuses a kind the game does not have, writing nothing', function () {
    $this->actingAs($this->leader)
        ->put(kindsUrl($this->mine), ['player_name' => 'A', 'platform_id' => '7656', 'id_kind' => 'GOG ID'])
        ->assertSessionHasErrors(['id_kind' => 'Hell Let Loose (WW2) has no ID kind "GOG ID".']);

    expect(TrooperGameIdentity::query()->count())->toBe(0)->and(AuditLogEntry::query()->count())->toBe(0);
});

it('audits a change of kind alone as an update with a different digest, and repeating it as nothing', function () {
    $this->actingAs($this->leader)->put(kindsUrl($this->mine), ['player_name' => 'A', 'platform_id' => '76561198000000656', 'id_kind' => 'Steam ID']);
    $this->actingAs($this->leader)->put(kindsUrl($this->mine), ['player_name' => 'A', 'platform_id' => '76561198000000656', 'id_kind' => 'Xbox ID'])->assertRedirect();
    $this->actingAs($this->leader)->put(kindsUrl($this->mine), ['player_name' => 'A', 'platform_id' => '76561198000000656', 'id_kind' => 'Xbox ID'])->assertRedirect();

    $log = AuditLogEntry::query()->orderBy('id')->get();

    expect(TrooperGameIdentity::query()->sole()->id_kind)->toBe('Xbox ID')
        ->and($log->pluck('action')->all())->toBe(['game_identity.linked', 'game_identity.updated'])
        ->and($log[0]->content_digest)->not->toBe($log[1]->content_digest);
});

it('keeps the kind an identity has when a save names none', function () {
    $this->actingAs($this->leader)->put(kindsUrl($this->mine), ['player_name' => 'A', 'platform_id' => '0002105c01b748ada8d8cf5b32e70093', 'id_kind' => 'Epic ID']);
    $this->actingAs($this->leader)->put(kindsUrl($this->mine), ['player_name' => 'Renamed', 'platform_id' => '0002105c01b748ada8d8cf5b32e70093'])->assertRedirect();

    expect(TrooperGameIdentity::query()->sole()->id_kind)->toBe('Epic ID');
});

it('never links one id twice on a game, whichever kind either was entered as', function () {
    $second = kindsTrooper($this->section, ['username' => 'Dube.R']);
    $this->actingAs($this->leader)->put(kindsUrl($this->mine), ['player_name' => 'A', 'platform_id' => '76561198000002105', 'id_kind' => 'Steam ID']);

    $this->actingAs($this->leader)
        ->put(kindsUrl($second), ['player_name' => 'B', 'platform_id' => '76561198000002105', 'id_kind' => 'Xbox ID'])
        ->assertSessionHasErrors(['platform_id' => 'Already linked to SGT Ortega.M on Hell Let Loose (WW2).']);

    expect(TrooperGameIdentity::query()->count())->toBe(1);
});

it('refuses a kind that is not a string', function () {
    $this->actingAs($this->leader)
        ->put(kindsUrl($this->mine), ['player_name' => 'A', 'platform_id' => '7656', 'id_kind' => ['Steam ID']])
        ->assertSessionHasErrors(['id_kind']);
});

it('refuses a kind removed since the game was loaded, writing nothing', function () {
    $stale = game('hll');
    $stale->update(['id_kinds' => ['Steam ID']]);

    expect(fn () => app(SetTrooperGameIdentityHandler::class)->handle($this->leader, new SetTrooperGameIdentity($this->mine->id, $stale->fresh()->setRawAttributes(['id_kinds' => json_encode(['Steam ID', 'Epic ID'])] + $stale->getAttributes()), 'A', '0002105c01b748ada8d8cf5b32e70093', idKind: 'Epic ID')))
        ->toThrow(UnknownIdKindException::class);

    expect(TrooperGameIdentity::query()->count())->toBe(0)->and(AuditLogEntry::query()->count())->toBe(0);
});

it('gives a replaced id the game\'s default kind, but keeps the kind when only the name changes (the unmatched-player link passes no kind)', function () {
    $handler = app(SetTrooperGameIdentityHandler::class);
    $handler->handle($this->leader, new SetTrooperGameIdentity($this->mine->id, game('hll'), 'A', '0002105c01b748ada8d8cf5b32e70093', idKind: 'Epic ID'));

    expect($handler->handle($this->leader, new SetTrooperGameIdentity($this->mine->id, game('hll'), 'Renamed', '0002105c01b748ada8d8cf5b32e70093'))->id_kind)->toBe('Epic ID')
        ->and($handler->handle($this->leader, new SetTrooperGameIdentity($this->mine->id, game('hll'), 'Renamed', '76561198000000222', '0002105c01b748ada8d8cf5b32e70093'))->id_kind)->toBe('Steam ID');
});

it('defaults an unnamed kind in the Command', function () {
    $identity = app(SetTrooperGameIdentityHandler::class)->handle($this->leader, new SetTrooperGameIdentity($this->mine->id, game('hll'), 'A', '76561198000000656'));

    expect($identity->id_kind)->toBe('Steam ID');
});
