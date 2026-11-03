<?php

use App\Integration\Steam\SteamApi;
use App\Models\AuditLogEntry;
use App\Models\IdentitySource;
use App\Models\KeptGameIdentity;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\KeptGameIdentityRepository;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// Identities kept on file for Cav names with no trooper in the portal, and for troopers who are not serving: exact
// name matching, who may keep them, the conflicts both ways, the rejoin on a roster sync, the Admin list and delete.

const KEPT_A = '76561198000000011';
const KEPT_B = '76561198000000012';

beforeEach(function () {
    config(['services.steam.web_api_key' => '']);
    app()->forgetInstance(SteamApi::class);

    $platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'path' => '960/']);
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $platoon->id, 'path' => '960/961/']);
    $this->leader = User::factory()->commanding($this->section)->create();
    $this->admin = User::factory()->serving()->create(['is_admin' => true]);
    $this->atkinsD = keptTrooper($this->section, 'Atkins.D');
    game('hll')->update(['id_kinds' => ['Steam ID', 'Epic ID', 'Xbox ID']]);
});

function keptTrooper(Unit $section, string $username, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create(['username' => $username, 'rank_short' => 'SGT', ...$attributes]);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

/** @return array<int, array<string, mixed>> */
function keptPreview(object $test, User $as, string $text, bool $replace = false): array
{
    return collect($test->actingAs($as)->postJson('/game-identities/import/preview', ['text' => $text, 'replace' => $replace])->assertOk()->json('lines'))->keyBy('line')->all();
}

function keptImport(object $test, User $as, string $text, array $expected, bool $replace = false): TestResponse
{
    return $test->actingAs($as)->post('/game-identities/import', ['text' => $text, 'replace' => $replace, 'expected' => $expected]);
}

function keptRow(string $name, string $platformId, array $attributes = []): KeptGameIdentity
{
    return KeptGameIdentity::query()->create(['cav_name' => $name, 'cav_name_key' => KeptGameIdentity::key($name), 'game_id' => game('hll')->id,
        'id_kind' => 'Steam ID', 'platform_id' => $platformId, ...$attributes]);
}

describe('exact name matching', function () {
    it('matches the Cav name exactly, ignoring case only, never by prefix', function () {
        $lines = keptPreview($this, $this->leader, 'atkins.d, HLL, '.KEPT_A."\nAtkins.DS, HLL, ".KEPT_B."\nAtkins, HLL, 76561198000000013\nAtkins.DA, HLL, 76561198000000014");

        expect($lines[1])->toMatchArray(['status' => 'add', 'trooper' => 'SGT Atkins.D'])
            ->and($lines[2]['status'])->toBe('refused')->and($lines[2]['reason'])->toContain('No trooper is named Atkins.DS')
            ->and($lines[3]['reason'])->toContain('No trooper is named Atkins.')
            ->and($lines[4]['reason'])->toContain('No trooper is named Atkins.DA');
    });

    it('keeps Atkins.DS apart from Atkins.D for an Admin', function () {
        $lines = keptPreview($this, $this->admin, 'Atkins.D, HLL, '.KEPT_A."\nAtkins.DS, HLL, ".KEPT_B);

        expect($lines[1])->toMatchArray(['status' => 'add', 'trooper' => 'SGT Atkins.D'])
            ->and($lines[2])->toMatchArray(['status' => 'keep', 'reason' => 'No trooper named Atkins.DS yet.']);
    });

    it('refuses a name two troopers carry in different cases', function () {
        keptTrooper($this->section, 'ATKINS.D');

        expect(keptPreview($this, $this->admin, 'Atkins.D, HLL, '.KEPT_A)[1]['reason'])->toBe('More than one trooper is named Atkins.D.');
    });
});

describe('kept on import', function () {
    it('keeps an unknown name for an Admin in the same audited batch, normalised', function () {
        $epic = str_repeat('AB', 16);
        $text = 'Atkins.D, HLL, '.KEPT_A."\nAtkins.DS, HLL, Epic ID, {$epic}, Dusty";

        expect(keptPreview($this, $this->admin, $text)[2])->toMatchArray(['status' => 'keep', 'platformId' => strtolower($epic)]);
        keptImport($this, $this->admin, $text, [1, 2])->assertSessionHasNoErrors();

        $kept = KeptGameIdentity::query()->sole();
        expect($kept)->toMatchArray(['cav_name' => 'Atkins.DS', 'cav_name_key' => 'atkins.ds', 'id_kind' => 'Epic ID', 'platform_id' => strtolower($epic), 'gamertag' => 'Dusty', 'created_by' => $this->admin->id])
            ->and($kept->source)->toBe(IdentitySource::Imported)
            ->and(TrooperGameIdentity::query()->sole()->trooper_id)->toBe($this->atkinsD->id)
            ->and(AuditLogEntry::query()->sole()->action)->toBe('game_identity.imported');
    });

    it('refuses an unknown name to a non-Admin, saying an Admin can keep it', function () {
        $line = keptPreview($this, $this->leader, 'Atkins.DS, HLL, '.KEPT_B)[1];

        expect($line['status'])->toBe('refused')->and($line['reason'])->toBe('No trooper is named Atkins.DS. An Admin can keep this ID on file for them.');
    });

    it('still checks the game, kind and shape of a kept line', function (string $text, string $reason) {
        $lines = keptPreview($this, $this->admin, $text);
        expect(end($lines)['reason'])->toContain($reason);
    })->with([
        'unknown game' => ['Atkins.DS, Tetris, '.KEPT_A, 'No game is called Tetris'],
        'kind not used' => ['Atkins.DS, HLL, PlayStation ID, abc', 'does not use PlayStation ID'],
        'bad shape' => ['Atkins.DS, HLL, Steam ID, 12345', 'not a valid Steam ID'],
        'repeated line' => ['Atkins.DS, HLL, '.KEPT_A."\nAtkins.DS, HLL, ".KEPT_B, 'Line 1 already gives Atkins.DS an identity'],
    ]);

    it('refuses an ID a trooper holds, or another kept name holds, naming them', function () {
        TrooperGameIdentity::factory()->create(['trooper_id' => $this->atkinsD->id, 'game_id' => game('hll')->id, 'platform_id' => KEPT_A, 'id_kind' => 'Steam ID']);
        keptRow('Atkins.A', KEPT_B);

        $lines = keptPreview($this, $this->admin, 'Atkins.DS, HLL, '.KEPT_A."\nAtkins.DA, HLL, ".KEPT_B);

        expect($lines[1]['reason'])->toBe('Already linked to SGT Atkins.D on Hell Let Loose (WW2).')
            ->and($lines[2]['reason'])->toBe('Already kept on file for Atkins.A on Hell Let Loose (WW2).');
    });

    it('refuses a trooper line whose ID is kept for a name', function () {
        keptRow('Atkins.DS', KEPT_A);

        expect(keptPreview($this, $this->leader, 'Atkins.D, HLL, '.KEPT_A)[1]['reason'])->toBe('Already kept on file for Atkins.DS on Hell Let Loose (WW2).');
    });

    it('shows a kept line again as unchanged, and replaces it only with the tick', function () {
        keptRow('Atkins.DS', KEPT_A);

        expect(keptPreview($this, $this->admin, 'atkins.ds, HLL, '.KEPT_A)[1]['status'])->toBe('unchanged')
            ->and(keptPreview($this, $this->admin, 'Atkins.DS, HLL, '.KEPT_B)[1]['status'])->toBe('skip')
            ->and(keptPreview($this, $this->admin, 'Atkins.DS, HLL, '.KEPT_B, replace: true)[1])->toMatchArray(['status' => 'keep', 'current' => KEPT_A]);
    });
});

describe('not serving', function () {
    beforeEach(function () {
        $this->left = keptTrooper($this->section, 'Atkins.A', ['status' => TrooperStatus::Inactive]);
    });

    it('adds a normal identity on a not serving trooper for an Admin', function () {
        $line = keptPreview($this, $this->admin, 'Atkins.A, HLL, '.KEPT_A)[1];
        expect($line)->toMatchArray(['status' => 'add', 'notServing' => true]);

        keptImport($this, $this->admin, 'Atkins.A, HLL, '.KEPT_A, [1])->assertSessionHasNoErrors();

        expect(TrooperGameIdentity::query()->sole()->trooper_id)->toBe($this->left->id)->and(KeptGameIdentity::query()->count())->toBe(0);
    });

    it('refuses a not serving trooper to a non-Admin, saying an Admin can add it', function () {
        expect(keptPreview($this, $this->leader, 'Atkins.A, HLL, '.KEPT_A)[1]['reason'])->toBe('Atkins.A is not serving. An Admin can add this ID for them.');
    });
});

describe('rejoin', function () {
    it('moves a kept identity onto the trooper of exactly that name', function () {
        keptRow('Atkins.DS', KEPT_A, ['gamertag' => 'Dusty']);
        keptRow('Atkins.DA', KEPT_B);
        $back = keptTrooper($this->section, 'atkins.ds');

        expect(app(KeptGameIdentityRepository::class)->attachRejoined())->toBe(['attached' => 1, 'conflicts' => 0]);

        $identity = TrooperGameIdentity::query()->sole();
        expect($identity)->toMatchArray(['trooper_id' => $back->id, 'platform_id' => KEPT_A, 'gamertag' => 'Dusty'])
            ->and($identity->source)->toBe(IdentitySource::Imported)
            ->and(KeptGameIdentity::query()->sole()->cav_name)->toBe('Atkins.DA');
    });

    it('leaves it kept and flagged when the trooper has the game or someone else holds the ID', function () {
        $held = keptRow('Atkins.DS', KEPT_A);
        $taken = keptRow('Atkins.DA', KEPT_B);
        $ds = keptTrooper($this->section, 'Atkins.DS');
        keptTrooper($this->section, 'Atkins.DA');
        TrooperGameIdentity::factory()->create(['trooper_id' => $ds->id, 'game_id' => game('hll')->id, 'platform_id' => '76561198000000019', 'id_kind' => 'Steam ID']);
        TrooperGameIdentity::factory()->create(['trooper_id' => $this->atkinsD->id, 'game_id' => game('hll')->id, 'platform_id' => KEPT_B, 'id_kind' => 'Steam ID']);

        expect(app(KeptGameIdentityRepository::class)->attachRejoined())->toBe(['attached' => 0, 'conflicts' => 2])
            ->and($held->fresh()->conflict)->toBe('The trooper already has an identity on this game.')
            ->and($taken->fresh()->conflict)->toBe('Another trooper holds this ID on this game.');
    });

    it('does nothing when no kept name has a trooper', function () {
        keptRow('Atkins.DS', KEPT_A);

        expect(app(KeptGameIdentityRepository::class)->attachRejoined())->toBe(['attached' => 0, 'conflicts' => 0])
            ->and(KeptGameIdentity::query()->count())->toBe(1);
    });
});

describe('list and delete', function () {
    it('lists kept identities to Admins only', function () {
        keptRow('Atkins.DS', KEPT_A, ['created_by' => $this->admin->id]);

        $this->actingAs($this->admin)->get('/game-identities')->assertInertia(fn (Assert $page) => $page
            ->where('kept.0.cavName', 'Atkins.DS')->where('kept.0.platformId', KEPT_A)->where('kept.0.game', 'Hell Let Loose (WW2)')->where('kept.0.addedBy', $this->admin->username));
        $this->actingAs($this->leader)->get('/game-identities')->assertInertia(fn (Assert $page) => $page->where('kept', null));
    });

    it('deletes a kept identity as an audited action with an optional reason, for Admins only', function () {
        $row = keptRow('Atkins.DS', KEPT_A);

        $this->actingAs($this->leader)->delete("/game-identities/kept/{$row->id}")->assertForbidden();
        $this->actingAs($this->admin)->delete("/game-identities/kept/{$row->id}", ['reason' => 'Duplicate'])->assertRedirect();

        $entry = AuditLogEntry::query()->sole();
        expect(KeptGameIdentity::query()->count())->toBe(0)
            ->and($entry->action)->toBe('kept_game_identity.removed')
            ->and($entry->reason)->toBe('Duplicate');
    });
});
