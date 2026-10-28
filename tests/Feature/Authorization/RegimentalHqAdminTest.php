<?php

use App\Authorization\ActAs;
use App\Authorization\ScopeResolver;
use App\Models\AdminBillet;
use App\Models\AdminBilletStatus;
use App\Models\AuditLogEntry;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AdminBilletRepository;
use App\Repositories\UnitRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia as Assert;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

// Admin by billet (decided by the S6, 2026-10-28): whoever serves on the regiment node as Regimental HQ staff is an
// Admin while an existing Admin has approved their billet and the billet still carries the title and group that were
// confirmed. Tied to the billet, read on every request, never written to `users.is_admin`.
// Fixture tree: 7CAV ─┬─ 1-7 ─ A/1-7 ─ 1/A/1-7 ─ 1/1/A/1-7
//                     └─ ELOA, NR, S6 (groups)

const RHQA_CHIEF = 'Regimental Chief of Staff';

const RHQA_GROUP = 'Regimental HQ';

function rhqaPlaced(?Unit $at, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $at?->id])->save();

    return $trooper;
}

/** An `admin_billets` row for `$positionId`, as an Admin would have left it after deciding. */
function rhqaBillet(int $positionId, string $title, AdminBilletStatus $status = AdminBilletStatus::Approved, string $group = RHQA_GROUP): AdminBillet
{
    $confirmed = $status === AdminBilletStatus::Approved;

    return AdminBillet::query()->create([
        'cav_position_id' => $positionId,
        'title' => $confirmed ? $title : null,
        'group_title' => $confirmed ? $group : null,
        'proposed_title' => $title,
        'proposed_group_title' => $group,
        'status' => $status,
    ]);
}

function rhqaIsAdmin(User $user): bool
{
    return app(ScopeResolver::class)->isAdmin($user->fresh());
}

/** The stored column itself, read past the model. */
function rhqaStoredFlag(User $user): bool
{
    return (bool) DB::table('users')->where('id', $user->id)->value('is_admin');
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);
    $this->groups = [
        'ELOA' => $units->create(UnitType::Group, 'Extended Leave of Absence', 'ELOA', $this->regiment->id, UnitKind::Eloa),
        'New Recruits' => $units->create(UnitType::Group, 'New Recruits', 'NR', $this->regiment->id, UnitKind::Recruit),
        'S6' => $units->create(UnitType::Group, 'S6', 'S6', $this->regiment->id, UnitKind::Staff),
    ];

    // The Chief of Staff: a Regimental HQ billet that commands nothing, placed on the regiment by the org import.
    $this->chiefTrooper = rhqaPlaced($this->regiment, ['username' => 'Tharen.R', 'billet' => RHQA_CHIEF, 'cav_position_id' => 9010]);
    $this->chief = User::factory()->create(['cav_id' => $this->chiefTrooper->cav_id, 'username' => 'Tharen.R']);
    $this->chiefBillet = rhqaBillet(9010, RHQA_CHIEF);

    // A Section Leader, by billet.
    UnitCommandMapping::factory()->create(['unit_id' => $this->section->id, 'cav_position_id' => 5900]);
    $leaderTrooper = rhqaPlaced($this->section, ['username' => 'Vasquez.R', 'billet' => 'Section Leader 1/1/A/1-7', 'cav_position_id' => 5900]);
    $this->leader = User::factory()->create(['cav_id' => $leaderTrooper->cav_id, 'username' => 'Vasquez.R']);

    // An Admin by the flag, with a milpac of their own.
    $this->admin = User::factory()->admin()->create(['username' => 'Hale.S', 'cav_id' => rhqaPlaced($this->section)->cav_id]);
});

describe('who is an Admin by billet', function () {
    it('makes a Chief of Staff on an approved billet an Admin on every Admin-only page, and only reads it', function () {
        foreach (['/settings/audit-log', '/settings/activity-log'] as $page) {
            $this->actingAs($this->chief)->get($page)->assertOk();
        }

        $this->actingAs($this->chief)->get('/settings/staff-grants')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('isAdmin', true)->where('auth.user.is_admin', true));
        $this->actingAs($this->chief)->get('/settings/org-structure')->assertOk();

        // An org structure write, as any Admin's.
        $this->actingAs($this->chief)->post('/settings/org-structure', [
            'type' => 'company', 'name' => 'Bravo Company', 'callsign' => 'B/1-7', 'parent_id' => $this->battalion->id,
        ])->assertRedirect(route('settings.org-structure'));
        expect(Unit::query()->where('callsign', 'B/1-7')->exists())->toBeTrue();

        // The whole tree to read and write, and the person options of view as and act as.
        $scope = app(ScopeResolver::class);
        expect($scope->writeScope($this->chief)->all())->toEqualCanonicalizing(Unit::query()->pluck('id')->all());
        $this->actingAs($this->chief)->getJson('/view-as/options')->assertOk()
            ->assertJsonPath('people', fn ($people) => is_array($people) && count($people) > 0);
    });

    it('is not an Admin while the billet waits for approval, or after it is rejected, but still reads the regiment', function (AdminBilletStatus $status) {
        $this->chiefBillet->delete();
        rhqaBillet(9010, RHQA_CHIEF, $status);

        expect(rhqaIsAdmin($this->chief))->toBeFalse();
        $this->actingAs($this->chief)->get('/settings/audit-log')->assertForbidden();
        $this->actingAs($this->chief)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.is_admin', false));
        // #499's read-only regiment still holds for Regimental HQ staff who are not (yet) Admins.
        expect(app(ScopeResolver::class)->realScope($this->chief)->contains($this->regiment->id))->toBeTrue()
            ->and(app(ScopeResolver::class)->writeScope($this->chief)->all())->toBe([]);
    })->with([
        'pending' => [AdminBilletStatus::Pending],
        'rejected' => [AdminBilletStatus::Rejected],
    ]);

    it('is not an Admin with no row for the billet at all', function () {
        $this->chiefBillet->delete();

        expect(rhqaIsAdmin($this->chief))->toBeFalse();
    });

    it('loses Admin on the next request once moved off the regiment', function () {
        $this->actingAs($this->chief)->get('/settings/audit-log')->assertOk();

        $this->chiefTrooper->forceFill(['primary_section_id' => $this->section->id])->save();

        $this->actingAs($this->chief)->get('/settings/audit-log')->assertForbidden();
        expect(rhqaIsAdmin($this->chief))->toBeFalse();
    });

    it('loses Admin on the next request once no longer serving', function () {
        $this->actingAs($this->chief)->get('/settings/audit-log')->assertOk();

        $this->chiefTrooper->forceFill(['status' => TrooperStatus::Inactive])->save();

        // The session check ends it there and then, as for any departed member who is not a flag Admin.
        $this->actingAs($this->chief)->get('/settings/audit-log')->assertRedirect(route('login'));
        expect(rhqaIsAdmin($this->chief))->toBeFalse();
    });

    it('stops at once when the billet is renamed, in its title or its group, until an Admin approves the new names', function (array $seen) {
        $this->actingAs($this->chief)->get('/settings/audit-log')->assertOk();

        app(AdminBilletRepository::class)->observe([9010 => $seen], createMissing: false);

        expect($this->chiefBillet->fresh()->status)->toBe(AdminBilletStatus::Pending);
        $this->actingAs($this->chief)->get('/settings/audit-log')->assertForbidden();

        $this->actingAs($this->admin)->post("/settings/staff-grants/admin-billets/{$this->chiefBillet->id}/approve", ['reason' => 'Renamed by Cav, same job'])
            ->assertSessionHasNoErrors();

        expect($this->chiefBillet->fresh())->title->toBe($seen[0])->status->toBe(AdminBilletStatus::Approved);
        if ($seen[0] !== RHQA_CHIEF) {
            // The holder's own synced title follows the roster sync, which renamed the billet too.
            $this->chiefTrooper->forceFill(['billet' => $seen[0]])->save();
        }
        $this->actingAs($this->chief)->get('/settings/audit-log')->assertOk();
    })->with([
        'a new title' => [['Regimental Chief of Staff (Acting)', null]],
        'a new group' => [[RHQA_CHIEF, 'Regimental Headquarters']],
    ]);

    it('is not an Admin when the holder\'s own synced title no longer matches the confirmed one', function () {
        $this->chiefTrooper->forceFill(['billet' => 'Regimental Chief of Staff (Acting)'])->save();

        expect(rhqaIsAdmin($this->chief))->toBeFalse();
    });

    it('never makes ELOA, S6, New Recruits or a battalion HQ member an Admin, even with an approved row', function (string $where) {
        $at = $where === 'battalion HQ' ? $this->battalion : $this->groups[$where];
        $trooper = rhqaPlaced($at, ['billet' => 'Staff Officer', 'cav_position_id' => 9500, 'status' => $where === 'ELOA' ? TrooperStatus::Eloa : TrooperStatus::Combat]);
        rhqaBillet(9500, 'Staff Officer');
        $user = User::factory()->create(['cav_id' => $trooper->cav_id]);

        expect(rhqaIsAdmin($user))->toBeFalse();
        $this->actingAs($user)->get('/settings/audit-log')->assertForbidden();
    })->with(['ELOA', 'S6', 'New Recruits', 'battalion HQ']);
});

describe('never stored', function () {
    it('never writes the billet Admin to users.is_admin, through requests, saves and a sign-in', function () {
        $this->actingAs($this->chief)->get('/settings/audit-log')->assertOk();
        $this->actingAs($this->chief)->post('/act-as', ['kind' => 'user', 'id' => $this->leader->id])->assertRedirect('/home');
        $this->actingAs($this->chief)->get('/home')->assertOk();
        $this->actingAs($this->chief)->delete('/act-as')->assertRedirect();

        $user = $this->chief->fresh();
        expect(app(ScopeResolver::class)->isAdmin($user))->toBeTrue();
        $user->forceFill(['username' => 'Tharen.R2'])->save();
        $user->update(['email' => 'tharen@example.test']);
        $user->fill(['username' => 'Tharen.R'])->save();

        expect(rhqaStoredFlag($this->chief))->toBeFalse()
            ->and(array_key_exists('notAdminByBillet', $user->getAttributes()))->toBeFalse()
            ->and(array_key_exists('notAdminByBillet', $user->toArray()))->toBeFalse();
    });

    it('signs a billet Admin in as an Admin, leaving the stored flag off', function () {
        config()->set('services.sevencav', [
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'http://localhost/auth/callback',
            'base_url' => 'https://7cav.us',
            'authorize_url' => 'https://7cav.us/oauth2/authorize',
            'scopes' => ['user:read'],
        ]);
        config()->set('services.cav', ['base_url' => 'https://api.7cav.us', 'api_key' => 'cav7_test-key']);
        Exceptions::fake();
        $this->chief->forceFill(['forum_user_id' => 4])->save();

        // Cav is down: the cached link and the serving trooper admit them, as for any member.
        MockClient::destroyGlobal();
        MockClient::global([
            '7cav.us/api/oauth2/token' => MockResponse::make(['access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            '7cav.us/api/me' => MockResponse::make(['me' => ['user_id' => 4, 'username' => 'Tharen.R', 'email' => 'tharen@7cav.us']]),
            "api.7cav.us/api/v1/milpacs/profile/id/{$this->chiefTrooper->cav_id}" => MockResponse::make([], 503),
        ]);

        $this->withSession(['forum_oauth_state' => 'state-xyz'])
            ->get('/auth/callback?code=auth-code&state=state-xyz')
            ->assertRedirect(route('home'));
        MockClient::destroyGlobal();

        $this->assertAuthenticatedAs($this->chief->fresh());
        $this->get('/settings/audit-log')->assertOk();
        expect(rhqaStoredFlag($this->chief))->toBeFalse();
    });
});

describe('act as and view as', function () {
    it('lets a billet Admin act as a person, who has no Admin rights', function () {
        $this->actingAs($this->chief)->post('/act-as', ['kind' => 'user', 'id' => $this->leader->id])->assertRedirect('/home');

        $this->actingAs($this->chief)->get('/home')->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $this->leader->id)
            ->where('auth.user.is_admin', false));
        $this->actingAs($this->chief)->get('/settings/audit-log')->assertForbidden();
    });

    it('gives an Admin acting as a billet Admin no Admin rights, only that person\'s own view', function () {
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->chief->id])->assertRedirect('/home');

        $this->actingAs($this->admin)->get('/home')->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $this->chief->id)
            ->where('auth.user.is_admin', false));
        $this->actingAs($this->admin)->get('/settings/audit-log')->assertForbidden();
        $this->actingAs($this->admin)->post('/settings/org-structure', [
            'type' => 'company', 'name' => 'Bravo Company', 'callsign' => 'B/1-7', 'parent_id' => $this->battalion->id,
        ])->assertForbidden();

        $plain = app(ActAs::class)->withoutAdmin($this->chief->fresh());
        expect(app(ScopeResolver::class)->isAdmin($plain))->toBeFalse()
            ->and(app(ScopeResolver::class)->writeScope($plain)->all())->toBe([]);
    });

    it('gives a billet Admin acting as a flag Admin no Admin rights either', function () {
        $this->actingAs($this->chief)->post('/act-as', ['kind' => 'user', 'id' => $this->admin->id])->assertRedirect('/home');

        $this->actingAs($this->chief)->get('/settings/audit-log')->assertForbidden();
    });

    it('holds a billet as a stand-in with no Admin rights', function () {
        $this->actingAs($this->chief)->post('/act-as', ['kind' => 'unit', 'id' => $this->section->id])->assertRedirect('/home');

        $this->actingAs($this->chief)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.is_admin', false));
        $this->actingAs($this->chief)->get('/settings/audit-log')->assertForbidden();
        expect(app(ActAs::class)->standIn($this->chief)->notAdminByBillet)->toBeTrue();
    });

    it('never lets a leader act as a billet Admin', function () {
        // The Regimental Commander commands the regiment by billet; their own billet is not (yet) an Admin billet.
        UnitCommandMapping::factory()->create(['unit_id' => $this->regiment->id, 'cav_position_id' => 9001]);
        $rco = User::factory()->create(['cav_id' => rhqaPlaced($this->regiment, ['billet' => 'Regimental Commanding Officer', 'cav_position_id' => 9001])->cav_id]);
        rhqaBillet(9001, 'Regimental Commanding Officer', AdminBilletStatus::Pending);
        // A portfolio officer: placed on the regiment, commanding the S6 portfolio below it, on an approved billet.
        UnitCommandMapping::factory()->create(['unit_id' => $this->groups['S6']->id, 'cav_position_id' => 9013]);
        $imo = User::factory()->create(['cav_id' => rhqaPlaced($this->regiment, ['billet' => 'Regimental Information Management Officer', 'cav_position_id' => 9013])->cav_id]);
        rhqaBillet(9013, 'Regimental Information Management Officer');

        expect(rhqaIsAdmin($imo))->toBeTrue()
            ->and(rhqaIsAdmin($rco))->toBeFalse()
            ->and(app(ActAs::class)->allows($rco, $imo))->toBeFalse()
            ->and(app(ActAs::class)->candidates($rco)->pluck('id')->all())->not->toContain($imo->id);

        $this->actingAs($rco)->post('/act-as', ['kind' => 'user', 'id' => $imo->id])->assertSessionHasErrors('act_as');
    });

    it('never makes a view-as preview an Admin, and lets a billet Admin preview a person', function () {
        $this->actingAs($this->chief)->post('/view-as', ['kind' => 'unit', 'id' => $this->section->id])->assertSessionHasNoErrors();
        $this->actingAs($this->chief)->get('/settings/audit-log')->assertForbidden();
        $this->actingAs($this->chief)->delete('/view-as');

        $this->actingAs($this->chief)->post('/view-as', ['kind' => 'user', 'id' => $this->leader->id])->assertSessionHasNoErrors();
        $this->actingAs($this->chief)->get('/home')->assertInertia(fn (Assert $page) => $page->where('viewAs.admin', false));
        $this->actingAs($this->chief)->delete('/view-as');

        // A leader still cannot preview a person, the billet Admin included.
        $this->actingAs($this->leader)->post('/view-as', ['kind' => 'user', 'id' => $this->chief->id])->assertSessionHasErrors('view_as');
    });

    it('marks a billet Admin as an Admin in the view as people list', function () {
        $people = collect($this->actingAs($this->admin)->getJson('/view-as/options')->assertOk()->json('people'));

        expect($people->firstWhere('id', $this->chief->id)['admin'])->toBeTrue()
            ->and($people->firstWhere('id', $this->leader->id)['admin'])->toBeFalse();
    });
});

describe('lists that leave Admins out', function () {
    it('keeps a billet Admin out of the staff grants user picker, and refuses them a grant', function () {
        $this->actingAs($this->admin)->get('/settings/staff-grants')->assertInertia(fn (Assert $page) => $page
            ->where('users', fn ($users) => collect($users)->pluck('id')->contains($this->leader->id)
                && ! collect($users)->pluck('id')->contains($this->chief->id)));

        $this->actingAs($this->admin)->post('/settings/staff-grants', [
            'user_id' => $this->chief->id, 'unit_id' => $this->section->id, 'reason' => 'S1 work',
        ])->assertSessionHasErrors('user_id');
    });

    it('answers the bulk Admin read as the single one does', function () {
        $ids = app(ScopeResolver::class)->adminIdsAmong(User::query()->get());
        $scopes = app(ScopeResolver::class)->realScopes(User::query()->get());

        expect($ids->all())->toEqualCanonicalizing([$this->chief->id, $this->admin->id])
            ->and($scopes->get($this->chief->id)->all())->toEqualCanonicalizing(Unit::query()->pluck('id')->all());
    });
});

describe('confirming billets', function () {
    beforeEach(function () {
        $this->financeTrooper = rhqaPlaced($this->regiment, ['billet' => 'Regimental Finance Officer', 'cav_position_id' => 9016]);
        $this->finance = User::factory()->create(['cav_id' => $this->financeTrooper->cav_id]);
        $this->financeBillet = rhqaBillet(9016, 'Regimental Finance Officer', AdminBilletStatus::Pending);
    });

    it('lets an approved billet Admin approve another billet, audited with the reason', function () {
        expect(rhqaIsAdmin($this->finance))->toBeFalse();

        $this->actingAs($this->chief)->post("/settings/staff-grants/admin-billets/{$this->financeBillet->id}/approve", ['reason' => 'Confirmed with the S6'])
            ->assertRedirect(route('settings.staff-grants'))
            ->assertSessionHasNoErrors();

        $billet = $this->financeBillet->fresh();
        expect($billet->status)->toBe(AdminBilletStatus::Approved)
            ->and($billet->title)->toBe('Regimental Finance Officer')
            ->and($billet->group_title)->toBe(RHQA_GROUP)
            ->and($billet->decided_by)->toBe($this->chief->id)
            ->and($billet->decided_at)->not->toBeNull()
            ->and(rhqaIsAdmin($this->finance))->toBeTrue()
            ->and(rhqaStoredFlag($this->finance))->toBeFalse();

        $entry = AuditLogEntry::query()->where('action', 'admin_billet.approved')->sole();
        expect($entry->actor_id)->toBe($this->chief->id)
            ->and($entry->target_type)->toBe('AdminBillet')
            ->and((string) $entry->target_id)->toBe((string) $this->financeBillet->id)
            ->and($entry->reason)->toBe('Confirmed with the S6')
            ->and($entry->on_behalf_of_id)->toBeNull();
    });

    it('rejects a billet, which stays rejected until a name changes, then waits again', function () {
        $this->actingAs($this->admin)->post("/settings/staff-grants/admin-billets/{$this->financeBillet->id}/reject", ['reason' => 'Not an Admin job'])
            ->assertSessionHasNoErrors();
        expect($this->financeBillet->fresh()->status)->toBe(AdminBilletStatus::Rejected)
            ->and(AuditLogEntry::query()->where('action', 'admin_billet.rejected')->count())->toBe(1)
            ->and(rhqaIsAdmin($this->finance))->toBeFalse();

        $repo = app(AdminBilletRepository::class);
        $repo->observe([9016 => ['Regimental Finance Officer', RHQA_GROUP]], createMissing: true);
        expect($this->financeBillet->fresh()->status)->toBe(AdminBilletStatus::Rejected);

        $repo->observe([9016 => ['Regimental Treasurer', null]], createMissing: false);
        expect($this->financeBillet->fresh())->status->toBe(AdminBilletStatus::Pending)->proposed_title->toBe('Regimental Treasurer');
    });

    it('refuses an approval of the billet the Admin holds themselves', function () {
        app(AdminBilletRepository::class)->observe([9010 => ['Regimental Chief of Staff (Acting)', null]], createMissing: false);

        $this->actingAs($this->chief)->post("/settings/staff-grants/admin-billets/{$this->chiefBillet->id}/approve", ['reason' => 'Mine'])
            ->assertForbidden();

        // A flag Admin who happens to hold it is refused too.
        $this->finance->forceFill(['cav_id' => null])->save();
        $this->admin->forceFill(['cav_id' => $this->financeTrooper->cav_id])->save();
        $this->actingAs($this->admin)->post("/settings/staff-grants/admin-billets/{$this->financeBillet->id}/approve", ['reason' => 'Mine'])
            ->assertSessionHasErrors(['adminBillet' => 'You hold this billet, so another Admin has to decide on it.']);

        expect($this->financeBillet->fresh()->status)->toBe(AdminBilletStatus::Pending)
            ->and(AuditLogEntry::query()->where('action', 'admin_billet.approved')->count())->toBe(0);
    });

    it('refuses a non-Admin, a pending billet holder included, before looking at the billet', function () {
        foreach ([$this->leader, $this->finance] as $user) {
            $this->actingAs($user)->post("/settings/staff-grants/admin-billets/{$this->financeBillet->id}/approve", ['reason' => 'x'])->assertForbidden();
            $this->actingAs($user)->post('/settings/staff-grants/admin-billets/999999/reject', ['reason' => 'x'])->assertForbidden();
        }

        expect($this->financeBillet->fresh()->status)->toBe(AdminBilletStatus::Pending);
    });

    it('requires a reason, and refuses a decision that changes nothing', function () {
        $this->actingAs($this->admin)->post("/settings/staff-grants/admin-billets/{$this->financeBillet->id}/approve", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->admin)->post("/settings/staff-grants/admin-billets/{$this->chiefBillet->id}/approve", ['reason' => 'Again'])
            ->assertSessionHasErrors(['adminBillet' => 'This billet is already approved with these names.']);
    });

    it('shows Admins the billets and how many wait, and nobody else', function () {
        $this->actingAs($this->admin)->get('/settings/staff-grants')->assertInertia(fn (Assert $page) => $page
            ->where('adminBilletsPending', 1)
            ->has('adminBillets', 2)
            ->where('adminBillets.0.id', $this->financeBillet->id)
            ->where('adminBillets.0.status', 'pending')
            ->where('adminBillets.0.holders', [$this->financeTrooper->display_name])
            ->where('adminBillets.1.id', $this->chiefBillet->id)
            ->where('adminBillets.1.own', false)
            ->where('regimentalHq', fn ($rows) => collect($rows)->firstWhere('id', $this->chiefTrooper->id)['admin'] === true
                && collect($rows)->firstWhere('id', $this->financeTrooper->id)['admin'] === false));

        $this->actingAs($this->chief)->get('/settings/staff-grants')->assertInertia(fn (Assert $page) => $page
            ->where('adminBillets.1.own', true));

        $this->actingAs($this->leader)->get('/settings/staff-grants')->assertInertia(fn (Assert $page) => $page
            ->where('adminBilletsPending', 0)
            ->where('adminBillets', []));
    });
});
