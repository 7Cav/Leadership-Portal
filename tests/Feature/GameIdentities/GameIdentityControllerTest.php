<?php

use App\Models\Attendance;
use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// 7.2 / AD-8, AD-16, AD-19, AD-22: the leader's link-identity screen and its audited writes.

beforeEach(function () {
    $this->platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'path' => '910/']);
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id, 'path' => '910/911/', 'callsign' => '1/A']);
    $this->other = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id, 'path' => '910/912/']);
    $this->leader = User::factory()->create();
    PortalRoleGrant::factory()->create(['user_id' => $this->leader->id, 'unit_id' => $this->section->id]);
    $this->mine = placedTrooper($this->section, ['username' => 'Ortega.M', 'rank_short' => 'SGT']);
    $this->theirs = placedTrooper($this->other, ['username' => 'Nakamura.T']);
});

function placedTrooper(Unit $section, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

function identityUrl(Trooper $trooper, string $platform = 'hll'): string
{
    return "/game-identities/{$trooper->id}/{$platform}";
}

it('lists only the troopers in scope with their identities and every platform slot', function () {
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->mine->id, 'player_name' => 'OrtegaCav', 'platform_id' => '7656']);
    placedTrooper($this->section, ['status' => TrooperStatus::Inactive]);

    $this->actingAs($this->leader)->get('/game-identities')->assertInertia(fn (Assert $page) => $page
        ->component('GameIdentities')
        ->has('platforms', 2)
        ->where('platforms.0', ['value' => 'hll', 'label' => 'Hell Let Loose', 'idLabel' => 'Steam ID'])
        ->has('troopers', 1)
        ->where('troopers.0.id', $this->mine->id)
        ->where('troopers.0.displayName', 'SGT Ortega.M')
        ->where('troopers.0.section', '1/A')
        ->where('troopers.0.identities.hll', ['playerName' => 'OrtegaCav', 'platformId' => '7656'])
        ->missing('troopers.0.identities.arma'));
});

it('shows an Admin the whole roster and a user with no scope nobody', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/game-identities')
        ->assertInertia(fn (Assert $page) => $page->has('troopers', 2));

    $this->actingAs(User::factory()->create())->get('/game-identities')
        ->assertInertia(fn (Assert $page) => $page->has('troopers', 0));
});

it('links a trooper through the audited Command', function () {
    $this->actingAs($this->leader)
        ->put(identityUrl($this->mine), ['player_name' => 'OrtegaCav', 'platform_id' => ' 7656 '])
        ->assertRedirect();

    $row = TrooperGameIdentity::query()->sole();
    expect($row->trooper_id)->toBe($this->mine->id)->and($row->platform_id)->toBe('7656');

    $log = AuditLogEntry::query()->sole();
    expect($log->action)->toBe('game_identity.linked')
        ->and($log->actor_id)->toBe($this->leader->id)
        ->and($log->target_type)->toBe('TrooperGameIdentity')
        ->and($log->target_id)->toBe((string) $row->id);
});

it('renames an identity in place and audits it as an update', function () {
    $this->actingAs($this->leader)->put(identityUrl($this->mine), ['player_name' => 'Old', 'platform_id' => '7656']);
    $this->actingAs($this->leader)->put(identityUrl($this->mine), ['player_name' => 'New', 'platform_id' => '7656'])->assertRedirect();

    expect(TrooperGameIdentity::query()->sole()->player_name)->toBe('New')
        ->and(AuditLogEntry::query()->pluck('action')->all())->toBe(['game_identity.linked', 'game_identity.updated']);
});

it('records nothing when the identity is saved unchanged', function () {
    $this->actingAs($this->leader)->put(identityUrl($this->mine), ['player_name' => 'Same', 'platform_id' => '7656']);
    $this->actingAs($this->leader)->put(identityUrl($this->mine), ['player_name' => 'Same', 'platform_id' => '7656'])->assertRedirect();

    expect(AuditLogEntry::query()->count())->toBe(1);
});

it('reports an identifier already linked elsewhere by the owner\'s name, writing nothing', function () {
    $this->actingAs($this->leader)->put(identityUrl($this->mine), ['player_name' => 'OrtegaCav', 'platform_id' => '7656']);
    $second = placedTrooper($this->section, ['username' => 'Dube.R']);

    $this->actingAs($this->leader)
        ->from('/game-identities')
        ->put(identityUrl($second), ['player_name' => 'Dubes', 'platform_id' => '7656'])
        ->assertRedirect('/game-identities')
        ->assertSessionHasErrors(['platform_id' => 'Already linked to SGT Ortega.M on Hell Let Loose.']);

    expect(TrooperGameIdentity::query()->count())->toBe(1)->and(AuditLogEntry::query()->count())->toBe(1);
});

it('allows the same identifier on a different platform', function () {
    $this->actingAs($this->leader)->put(identityUrl($this->mine, 'hll'), ['player_name' => 'A', 'platform_id' => 'same-id']);
    $this->actingAs($this->leader)->put(identityUrl($this->mine, 'arma'), ['player_name' => 'A', 'platform_id' => 'same-id'])->assertRedirect();

    expect(TrooperGameIdentity::query()->count())->toBe(2);
});

it('validates the fields and the platform', function () {
    $this->actingAs($this->leader)->put(identityUrl($this->mine), ['player_name' => '', 'platform_id' => ''])
        ->assertSessionHasErrors(['player_name', 'platform_id']);
    $this->actingAs($this->leader)->put(identityUrl($this->mine, 'wardogs'), ['player_name' => 'A', 'platform_id' => 'B'])->assertNotFound();
});

it('removes an identity, audited, leaving attendance untouched', function () {
    $this->actingAs($this->leader)->put(identityUrl($this->mine), ['player_name' => 'OrtegaCav', 'platform_id' => '7656']);
    $attendance = Attendance::factory()->create(['trooper_id' => $this->mine->id]);

    $this->actingAs($this->leader)->delete(identityUrl($this->mine))->assertRedirect();

    expect(TrooperGameIdentity::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->latest('id')->first()->action)->toBe('game_identity.removed')
        ->and(Attendance::query()->whereKey($attendance->id)->exists())->toBeTrue();
});

it('records nothing when removing a link that is not there', function () {
    $this->actingAs($this->leader)->delete(identityUrl($this->mine))->assertRedirect();

    expect(AuditLogEntry::query()->count())->toBe(0);
});

it('denies every write for a trooper outside the scope, whatever the UI offered', function () {
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->theirs->id, 'platform_id' => '9']);

    $this->actingAs($this->leader)->put(identityUrl($this->theirs), ['player_name' => 'X', 'platform_id' => '1'])->assertForbidden();
    $this->actingAs($this->leader)->delete(identityUrl($this->theirs))->assertForbidden();
    $this->actingAs($this->leader)->put('/game-identities/999999/hll', ['player_name' => 'X', 'platform_id' => '1'])->assertForbidden();

    expect(TrooperGameIdentity::query()->sole()->platform_id)->toBe('9')->and(AuditLogEntry::query()->count())->toBe(0);
});

it('denies an unplaced or departed trooper', function () {
    $unplaced = Trooper::factory()->create();
    $departed = placedTrooper($this->section, ['status' => TrooperStatus::Inactive]);

    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)->put(identityUrl($unplaced), ['player_name' => 'X', 'platform_id' => '1'])->assertForbidden();
    $this->actingAs($admin)->put(identityUrl($departed), ['player_name' => 'X', 'platform_id' => '1'])->assertForbidden();
});

it('requires a signed-in user', function () {
    $this->get('/game-identities')->assertRedirect('/login');
    $this->put(identityUrl($this->mine), [])->assertRedirect('/login');
});
