<?php

use App\Commands\GameIdentities\SetTrooperGameIdentity;
use App\Commands\GameIdentities\SetTrooperGameIdentityHandler;
use App\Commands\GameIdentities\UnmatchedPlayerResolutionException;
use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\GamePlayerDismissal;
use App\Models\PracticeCapturePlayer;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\PracticeCapturePlayerRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

// 7.7 / AD-19, AD-16, AD-8, AD-14: the leader resolves the players a capture could not match.

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'path' => '930/']);
    $this->leader = User::factory()->commanding($this->section)->create(['username' => 'Vasquez.R']);
    $this->ortega = unmatchedTrooper($this->section, ['username' => 'Ortega.M', 'rank_short' => 'SGT']);
    $this->practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => '2026-10-03 00:00:00']);
    $this->practice->forceFill(['captured_at' => '2026-10-08 12:00:00', 'capture_source' => 'crcon', 'server_ref' => 'crcon-1'])->save();
    $this->seen = capturedPlayer($this->practice, 'Ortega', '7656119000000001');
});

function unmatchedTrooper(Unit $section, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

function capturedPlayer(SectionPractice $practice, string $name, string $id, ?int $trooperId = null): PracticeCapturePlayer
{
    return PracticeCapturePlayer::query()->create([
        'section_practice_id' => $practice->id, 'game_id' => game('hll')->id, 'player_name' => $name, 'platform_id' => $id, 'trooper_id' => $trooperId,
    ]);
}

function linkBody(object $test, array $override = []): array
{
    return ['week' => '2026-10-03', 'platform' => 'hll', 'platform_id' => '7656119000000001', 'trooper_id' => $test->ortega->id, ...$override];
}

it('lists the unmatched players of a captured practice, with the capture seam for the later stories', function () {
    capturedPlayer($this->practice, 'Linked Elsewhere', '7656119000000009', unmatchedTrooper(Unit::factory()->create(['type' => UnitType::Section]))->id);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")->assertInertia(fn (Assert $page) => $page
        ->where('capture', ['source' => 'crcon', 'capturedAt' => '2026-10-08T12:00:00+00:00'])
        ->has('unmatched', 1)
        ->where('unmatched.0', ['platform' => 'hll', 'platformLabel' => 'Hell Let Loose', 'playerName' => 'Ortega', 'platformId' => '7656119000000001']));
});

it('shows no drawer data for a practice that was never captured', function () {
    $this->practice->forceFill(['captured_at' => null, 'capture_source' => null, 'server_ref' => null])->save();

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn (Assert $page) => $page->where('capture', null)->where('unmatched', []));
});

it('links the player to the chosen trooper and pre-marks them present as auto', function () {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/link", linkBody($this))
        ->assertOk()
        ->assertJsonPath('marked', true)
        ->assertJsonPath('trooper', ['id' => $this->ortega->id, 'status' => 'present', 'source' => 'auto']);

    $identity = TrooperGameIdentity::query()->sole();
    expect($identity->trooper_id)->toBe($this->ortega->id)
        ->and($identity->player_name)->toBe('Ortega')
        ->and(AuditLogEntry::query()->where('action', 'game_identity.linked')->count())->toBe(1)
        ->and(AuditLogEntry::query()->where('action', 'attendance.auto_marked')->count())->toBe(1);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")->assertInertia(fn (Assert $page) => $page->where('unmatched', []));
});

it('takes the player name from the stored capture, not from the request', function () {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/link", linkBody($this, ['player_name' => 'Forged']))->assertOk();

    expect(TrooperGameIdentity::query()->sole()->player_name)->toBe('Ortega');
});

it('never overwrites a manual mark when linking (manual wins)', function () {
    Attendance::factory()->create([
        'trooper_id' => $this->ortega->id, 'section_practice_id' => $this->practice->id,
        'status' => AttendanceStatus::Missed, 'source' => AttendanceSource::Manual,
    ]);

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/link", linkBody($this))
        ->assertOk()
        ->assertJsonPath('marked', false)
        ->assertJsonPath('trooper', ['id' => $this->ortega->id, 'status' => 'missed', 'source' => 'manual']);

    expect(TrooperGameIdentity::query()->count())->toBe(1);
});

it('refuses to link a player of a retired game, and changes nothing (#388)', function () {
    game('hll')->forceFill(['retired_at' => now()])->save();

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/link", linkBody($this))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Hell Let Loose is retired, so no identity can be linked to it.');

    expect(TrooperGameIdentity::query()->count())->toBe(0)
        ->and(Attendance::query()->count())->toBe(0);
});

it('refuses a game that does not exist, to link, dismiss or undo', function (string $action) {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/{$action}", linkBody($this, ['platform' => 'quake']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('platform');
})->with(['link', 'dismiss', 'restore']);

it('refuses to link without a trooper chosen: the system never picks one (AD-19)', function () {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/link", linkBody($this, ['trooper_id' => null]))
        ->assertStatus(422);

    expect(TrooperGameIdentity::query()->count())->toBe(0);
});

it('denies a trooper outside the leader\'s scope server-side (AD-8)', function () {
    $outsider = unmatchedTrooper(Unit::factory()->create(['type' => UnitType::Section, 'path' => '931/']));

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/link", linkBody($this, ['trooper_id' => $outsider->id]))
        ->assertForbidden();

    expect(TrooperGameIdentity::query()->count())->toBe(0);
});

it('denies a section outside the leader\'s scope for link and dismiss', function () {
    $stranger = User::factory()->serving()->create();

    $this->actingAs($stranger)->postJson("/rollcall/{$this->section->id}/unmatched/link", linkBody($this))->assertForbidden();
    $this->actingAs($stranger)->postJson("/rollcall/{$this->section->id}/unmatched/dismiss", linkBody($this))->assertForbidden();

    expect(TrooperGameIdentity::query()->count())->toBe(0)->and(GamePlayerDismissal::query()->count())->toBe(0);
});

it('refuses a trooper from another section of the leader\'s scope', function () {
    $sibling = Unit::factory()->create(['type' => UnitType::Section, 'path' => '930/932/']);
    $other = unmatchedTrooper($sibling);
    $this->section->forceFill(['path' => '930/'])->save();
    // An Admin, so the other section is in scope too: the refusal is about the section, not scope.
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson("/rollcall/{$this->section->id}/unmatched/link", linkBody($this, ['trooper_id' => $other->id]))
        ->assertStatus(422);

    expect(TrooperGameIdentity::query()->count())->toBe(0);
});

it('surfaces "Already linked to <trooper> on <game>" instead of reassigning', function () {
    $owner = unmatchedTrooper($this->section, ['username' => 'Nakamura.T', 'rank_short' => 'PFC']);
    TrooperGameIdentity::factory()->create(['trooper_id' => $owner->id, 'game_id' => game('hll')->id, 'platform_id' => '7656119000000001']);
    // The capture stored this player as unmatched before the other link was made.

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/link", linkBody($this))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Already linked to PFC Nakamura.T on Hell Let Loose.');

    expect(TrooperGameIdentity::query()->sole()->trooper_id)->toBe($owner->id)
        ->and(Attendance::query()->count())->toBe(0);
});

it('does not replace a different identity the chosen trooper already holds on that game, and says which so the leader can choose', function () {
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->ortega->id, 'game_id' => game('hll')->id, 'player_name' => 'OrtegaMain', 'platform_id' => '7656119000000099']);
    $before = AuditLogEntry::query()->count();

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/link", linkBody($this))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'has_other_identity')
        ->assertJsonPath('current', ['playerName' => 'OrtegaMain', 'platformId' => '7656119000000099'])
        ->assertJsonPath('message', 'That trooper is already linked to OrtegaMain (7656119000000099) on Hell Let Loose. Replace it, or cancel to keep it.');

    expect(TrooperGameIdentity::query()->sole()->platform_id)->toBe('7656119000000099')
        ->and(AuditLogEntry::query()->count())->toBe($before)
        ->and(Attendance::query()->count())->toBe(0);
});

// #176: "replace or cancel". Replacing goes through the 7.2 writer as an audited update, then the 7.7 pre-mark.

it('replaces the trooper\'s identity on that game when the leader chose to, audited, and pre-marks them', function () {
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->ortega->id, 'game_id' => game('hll')->id, 'player_name' => 'OrtegaMain', 'platform_id' => '7656119000000099']);

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/link", linkBody($this, ['replace' => true, 'replacing' => '7656119000000099']))
        ->assertOk()
        ->assertJsonPath('marked', true)
        ->assertJsonPath('trooper', ['id' => $this->ortega->id, 'status' => 'present', 'source' => 'auto']);

    $identity = TrooperGameIdentity::query()->sole();
    expect($identity->only(['trooper_id', 'player_name', 'platform_id']))->toBe(['trooper_id' => $this->ortega->id, 'player_name' => 'Ortega', 'platform_id' => '7656119000000001'])
        ->and(AuditLogEntry::query()->where('action', 'game_identity.updated')->where('actor_id', $this->leader->id)->count())->toBe(1)
        ->and(AuditLogEntry::query()->where('action', 'game_identity.linked')->count())->toBe(0)
        ->and(AuditLogEntry::query()->where('action', 'attendance.auto_marked')->count())->toBe(1);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")->assertInertia(fn (Assert $page) => $page->where('unmatched', []));
});

it('keeps a manual mark when replacing (manual wins)', function () {
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->ortega->id, 'game_id' => game('hll')->id, 'player_name' => 'OrtegaMain', 'platform_id' => '7656119000000099']);
    Attendance::factory()->create([
        'trooper_id' => $this->ortega->id, 'section_practice_id' => $this->practice->id,
        'status' => AttendanceStatus::Missed, 'source' => AttendanceSource::Manual,
    ]);

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/link", linkBody($this, ['replace' => true, 'replacing' => '7656119000000099']))
        ->assertOk()
        ->assertJsonPath('marked', false)
        ->assertJsonPath('trooper', ['id' => $this->ortega->id, 'status' => 'missed', 'source' => 'manual']);

    expect(TrooperGameIdentity::query()->sole()->platform_id)->toBe('7656119000000001');
});

it('never reassigns an identifier another trooper owns, even with replace, and names that trooper', function () {
    $owner = unmatchedTrooper($this->section, ['username' => 'Nakamura.T', 'rank_short' => 'PFC']);
    TrooperGameIdentity::factory()->create(['trooper_id' => $owner->id, 'game_id' => game('hll')->id, 'platform_id' => '7656119000000001']);
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->ortega->id, 'game_id' => game('hll')->id, 'player_name' => 'OrtegaMain', 'platform_id' => '7656119000000099']);
    $before = AuditLogEntry::query()->count();

    // Without replace it names the owner too, rather than asking to replace a link that could not be made.
    foreach ([[], ['replace' => true, 'replacing' => '7656119000000099']] as $override) {
        $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/link", linkBody($this, $override))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Already linked to PFC Nakamura.T on Hell Let Loose.')
            ->assertJsonMissingPath('reason');
    }

    expect(TrooperGameIdentity::query()->where('trooper_id', $this->ortega->id)->sole()->platform_id)->toBe('7656119000000099')
        ->and(TrooperGameIdentity::query()->where('platform_id', '7656119000000001')->sole()->trooper_id)->toBe($owner->id)
        ->and(AuditLogEntry::query()->count())->toBe($before)
        ->and(Attendance::query()->count())->toBe(0);
});

it('denies a replace for a trooper outside the leader\'s scope, server-side (AD-8)', function () {
    $outsider = unmatchedTrooper(Unit::factory()->create(['type' => UnitType::Section, 'path' => '931/']));
    TrooperGameIdentity::factory()->create(['trooper_id' => $outsider->id, 'game_id' => game('hll')->id, 'player_name' => 'Theirs', 'platform_id' => '7656119000000099']);

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/link", linkBody($this, ['trooper_id' => $outsider->id, 'replace' => true, 'replacing' => '7656119000000099']))
        ->assertForbidden();

    expect(TrooperGameIdentity::query()->sole()->platform_id)->toBe('7656119000000099');
});

// #176 race: the replace names the ID the leader was shown, and overwrites only that one.

it('refuses a replace whose ID changed since the leader was asked, with the new one, and changes nothing', function () {
    // The leader was shown OrtegaMain (…0099); another leader changed it to OrtegaAlt (…0077) before Replace landed.
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->ortega->id, 'game_id' => game('hll')->id, 'player_name' => 'OrtegaAlt', 'platform_id' => '7656119000000077']);
    $before = AuditLogEntry::query()->count();

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/link", linkBody($this, ['replace' => true, 'replacing' => '7656119000000099']))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'has_other_identity')
        ->assertJsonPath('current', ['playerName' => 'OrtegaAlt', 'platformId' => '7656119000000077'])
        ->assertJsonPath('message', 'That trooper is already linked to OrtegaAlt (7656119000000077) on Hell Let Loose. Replace it, or cancel to keep it.');

    expect(TrooperGameIdentity::query()->sole()->only(['player_name', 'platform_id']))->toBe(['player_name' => 'OrtegaAlt', 'platform_id' => '7656119000000077'])
        ->and(AuditLogEntry::query()->count())->toBe($before)
        ->and(Attendance::query()->count())->toBe(0);
});

it('refuses a change landing between the link\'s check and its write, inside the audited transaction', function () {
    // The writer is what the link handler calls once its own check passed: by then the ID is …0077, not the …0099 shown.
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->ortega->id, 'game_id' => game('hll')->id, 'player_name' => 'OrtegaAlt', 'platform_id' => '7656119000000077']);
    $before = AuditLogEntry::query()->count();

    try {
        app(SetTrooperGameIdentityHandler::class)->handle($this->leader, new SetTrooperGameIdentity($this->ortega->id, game('hll'), 'Ortega', '7656119000000001', '7656119000000099'));
        $this->fail('The stale replace was written.');
    } catch (UnmatchedPlayerResolutionException $e) {
        expect($e->reason)->toBe('has_other_identity')
            ->and($e->current)->toBe(['playerName' => 'OrtegaAlt', 'platformId' => '7656119000000077']);
    }

    expect(TrooperGameIdentity::query()->sole()->platform_id)->toBe('7656119000000077')
        ->and(AuditLogEntry::query()->count())->toBe($before);
});

it('links when the identity the leader agreed to replace was removed in the meantime: nothing is overwritten', function () {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/link", linkBody($this, ['replace' => true, 'replacing' => '7656119000000099']))
        ->assertOk()
        ->assertJsonPath('marked', true);

    expect(TrooperGameIdentity::query()->sole()->platform_id)->toBe('7656119000000001')
        ->and(AuditLogEntry::query()->where('action', 'game_identity.linked')->count())->toBe(1);
});

it('refuses a replace that does not say which ID it replaces, and changes nothing', function () {
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->ortega->id, 'game_id' => game('hll')->id, 'player_name' => 'OrtegaMain', 'platform_id' => '7656119000000099']);
    $before = AuditLogEntry::query()->count();

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/link", linkBody($this, ['replace' => true]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('replacing')
        ->assertJsonPath('message', 'This replace does not say which ID it replaces, so nothing was changed. Reload the page and link the player again.');

    expect(TrooperGameIdentity::query()->sole()->platform_id)->toBe('7656119000000099')
        ->and(AuditLogEntry::query()->count())->toBe($before)
        ->and(Attendance::query()->count())->toBe(0);
});

it('refuses a replace flag that is not a boolean', function () {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/link", linkBody($this, ['replace' => 'please']))
        ->assertStatus(422);

    expect(TrooperGameIdentity::query()->count())->toBe(0);
});

it('answers 422 for a player the practice never listed', function () {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/link", linkBody($this, ['platform_id' => 'nobody']))
        ->assertStatus(422);
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/dismiss", linkBody($this, ['platform_id' => 'nobody']))
        ->assertStatus(422);
});

it('dismisses a player with an audit entry, and they leave this and later lists', function () {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/dismiss", linkBody($this))
        ->assertOk()->assertJsonPath('dismissed', true);

    expect(GamePlayerDismissal::query()->sole()->only(['platform_id', 'dismissed_by']))->toBe(['platform_id' => '7656119000000001', 'dismissed_by' => $this->leader->id])
        ->and(AuditLogEntry::query()->where('action', 'game_player.dismissed')->count())->toBe(1);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")->assertInertia(fn (Assert $page) => $page->where('unmatched', []));

    // A later practice that captures the same player does not list them either.
    $next = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => '2026-10-10 00:00:00']);
    capturedPlayer($next, 'Ortega', '7656119000000001');
    expect(app(PracticeCapturePlayerRepository::class)->unmatchedFor($next->id))->toHaveCount(0);
});

// #176: a dismissal can be undone, from the toast or the drawer's "Dismissed" list.

it('lists this practice\'s dismissed players for the drawer, and undoing one lists them again, audited', function () {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/dismiss", linkBody($this))->assertOk();

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")->assertInertia(fn (Assert $page) => $page
        ->where('unmatched', [])
        ->where('dismissed', [['platform' => 'hll', 'platformLabel' => 'Hell Let Loose', 'playerName' => 'Ortega', 'platformId' => '7656119000000001']]));

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/restore", linkBody($this))
        ->assertOk()->assertJsonPath('restored', true);

    $entry = AuditLogEntry::query()->where('action', 'game_player.restored')->sole();
    expect($entry->actor_id)->toBe($this->leader->id)
        ->and(GamePlayerDismissal::query()->count())->toBe(0);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")->assertInertia(fn (Assert $page) => $page
        ->where('dismissed', [])
        ->where('unmatched.0.platformId', '7656119000000001'));

    // And on later practices that see them.
    $next = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => '2026-10-10 00:00:00']);
    capturedPlayer($next, 'Ortega', '7656119000000001');
    expect(app(PracticeCapturePlayerRepository::class)->unmatchedFor($next->id))->toHaveCount(1);
});

it('does not list a dismissal of a player this practice never saw', function () {
    GamePlayerDismissal::query()->create(['game_id' => game('hll')->id, 'platform_id' => '7656119000000777', 'player_name' => 'Elsewhere', 'dismissed_by' => $this->leader->id]);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")->assertInertia(fn (Assert $page) => $page->where('dismissed', []));
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/restore", linkBody($this, ['platform_id' => '7656119000000777']))
        ->assertStatus(422);

    expect(GamePlayerDismissal::query()->count())->toBe(1);
});

it('answers a clean 422 for undoing a player who is not dismissed, and records nothing', function () {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/restore", linkBody($this))
        ->assertStatus(422)
        ->assertJsonPath('message', 'That player is not dismissed, so there is nothing to undo. Reload to see the current list.');

    expect(AuditLogEntry::query()->count())->toBe(0);
});

it('denies undoing a dismissal for a section outside the leader\'s scope (AD-8)', function () {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/dismiss", linkBody($this))->assertOk();
    $stranger = User::factory()->serving()->create();

    $this->actingAs($stranger)->postJson("/rollcall/{$this->section->id}/unmatched/restore", linkBody($this))->assertForbidden();

    expect(GamePlayerDismissal::query()->count())->toBe(1)
        ->and(AuditLogEntry::query()->where('action', 'game_player.restored')->count())->toBe(0);
});

it('refuses an undo with no week or platform', function () {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/restore", ['platform_id' => '7656119000000001'])->assertStatus(422);
});

it('lists a dismissed player again once they are linked, then unlinked', function () {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/dismiss", linkBody($this))->assertOk();

    // Linked on the 7.2 screen: the dismissal is cleared by the identity writer.
    $this->actingAs($this->leader)->put("/game-identities/{$this->ortega->id}/hll", ['player_name' => 'Ortega', 'platform_id' => '7656119000000001'])->assertRedirect();
    expect(GamePlayerDismissal::query()->count())->toBe(0);

    $this->actingAs($this->leader)->delete("/game-identities/{$this->ortega->id}/hll")->assertRedirect();

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")->assertInertia(fn (Assert $page) => $page->has('unmatched', 1));
});

it('does not dismiss a player who has been linked since the capture', function () {
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->ortega->id, 'game_id' => game('hll')->id, 'platform_id' => '7656119000000001']);

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/dismiss", linkBody($this))->assertStatus(422);

    expect(GamePlayerDismissal::query()->count())->toBe(0);
});

it('is idempotent when the same link is submitted twice', function () {
    $url = "/rollcall/{$this->section->id}/unmatched/link";
    $this->actingAs($this->leader)->postJson($url, linkBody($this))->assertOk()->assertJsonPath('marked', true);
    $this->actingAs($this->leader)->postJson($url, linkBody($this))->assertOk()->assertJsonPath('marked', true);

    expect(TrooperGameIdentity::query()->count())->toBe(1)
        ->and(AuditLogEntry::query()->where('action', 'game_identity.linked')->count())->toBe(1)
        ->and(Attendance::query()->count())->toBe(1);
});

it('refuses to link or dismiss a player the capture stored as linked to another section', function () {
    $elsewhere = unmatchedTrooper(Unit::factory()->create(['type' => UnitType::Section, 'path' => '933/']));
    capturedPlayer($this->practice, 'Visitor', '7656119000000050', $elsewhere->id);

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/link", linkBody($this, ['platform_id' => '7656119000000050']))->assertStatus(422);
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/dismiss", linkBody($this, ['platform_id' => '7656119000000050']))->assertStatus(422);

    expect(TrooperGameIdentity::query()->count())->toBe(0)->and(GamePlayerDismissal::query()->count())->toBe(0);
});

it('keeps an existing LOA mark when linking', function () {
    Attendance::factory()->create([
        'trooper_id' => $this->ortega->id, 'section_practice_id' => $this->practice->id,
        'status' => AttendanceStatus::Loa, 'source' => AttendanceSource::Manual,
    ]);

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/link", linkBody($this))
        ->assertOk()->assertJsonPath('marked', false)->assertJsonPath('trooper.status', 'loa');
});

it('leaves no audit trail behind a refused collision, and nothing is consumed by a denied link', function () {
    $owner = unmatchedTrooper($this->section);
    TrooperGameIdentity::factory()->create(['trooper_id' => $owner->id, 'game_id' => game('hll')->id, 'platform_id' => '7656119000000001']);
    $before = AuditLogEntry::query()->count();

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/unmatched/link", linkBody($this))->assertStatus(422);

    expect(AuditLogEntry::query()->count())->toBe($before);
});
