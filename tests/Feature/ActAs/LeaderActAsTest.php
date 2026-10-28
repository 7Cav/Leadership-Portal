<?php

use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitActingLeadRepository;
use App\Repositories\UnitRepository;
use Inertia\Testing\AssertableInertia as Assert;

// #432: a leader acts as a serving billet holder strictly below them inside their own write scope, with exactly that
// person's permissions, under the same red bar. Every entry names the leader as actor and the person acted for; the
// choice is re-checked on every request; a staff grant (read-only) confers nothing; an Admin acting as a leader cannot
// use the leader's act-as to reach beyond the leader's scope.
//
//   Alpha Company (CO 6000)
//     1st Platoon (PL 6100)           2nd Platoon (PL 6101)
//       1st Section (SL 6200)           3rd Section (SL 6202)
//       2nd Section (SL 6201)

function leaderPerson(int $cavId, string $username, ?int $position, ?TrooperStatus $status = null, array $user = []): User
{
    Trooper::factory()->create(['cav_id' => $cavId, 'cav_position_id' => $position, 'status' => $status ?? TrooperStatus::Combat, 'rank' => 'SGT', 'username' => $username]);

    return User::factory()->create(['cav_id' => $cavId, 'username' => $username] + $user);
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->regiment->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->platoon2 = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->company->id);
    $this->section1 = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->section2 = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->platoon->id);
    $this->section3 = $units->create(UnitType::Section, '3rd Section', '1/2/A/1-7', $this->platoon2->id);

    foreach ([6000 => $this->company, 6100 => $this->platoon, 6101 => $this->platoon2, 6200 => $this->section1, 6201 => $this->section2, 6202 => $this->section3] as $position => $unit) {
        UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $position]);
    }

    $this->co = leaderPerson(900, 'Okafor.T', 6000);
    $this->pl = leaderPerson(901, 'Hale.S', 6100);
    $this->pl2 = leaderPerson(902, 'Brandt.K', 6101);
    $this->sl1 = leaderPerson(903, 'Vasquez.R', 6200);
    $this->sl2 = leaderPerson(904, 'Mercer.J', 6201);
    $this->sl3 = leaderPerson(905, 'Lindqvist.E', 6202);
});

// Each request signs the leader in afresh, as a real request does: the session is theirs, and only the request's user
// is swapped for the person acted as.
function leaderStarts(object $test, User $leader, User $as): void
{
    $test->actingAs($leader)->post('/act-as', ['kind' => 'user', 'id' => $as->id])->assertRedirect('/home');
}

function leaderCannotStart(object $test, User $leader, User $as): void
{
    $test->actingAs($leader)->post('/act-as', ['kind' => 'user', 'id' => $as->id])->assertSessionHasErrors('act_as');
    expect(AuditLogEntry::where('action', 'act_as.started')->count())->toBe(0);
    $test->actingAs($leader)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $leader->id)->where('actingAs', null));
}

describe('starting', function () {
    it('lets a platoon leader act as a section leader under them, recorded with the leader as actor', function () {
        leaderStarts($this, $this->pl, $this->sl1);

        $entry = AuditLogEntry::where('action', 'act_as.started')->sole();
        expect($entry->actor_id)->toBe($this->pl->id)
            ->and($entry->target_type)->toBe('User')
            ->and($entry->target_id)->toBe((string) $this->sl1->id)
            ->and($entry->on_behalf_of_id)->toBeNull();
    });

    it('lets a commander act as anyone below, at any depth', function (string $who) {
        leaderStarts($this, $this->co, $this->{$who});

        expect(AuditLogEntry::where('action', 'act_as.started')->sole()->target_id)->toBe((string) $this->{$who}->id);
    })->with(['platoon leader' => ['pl'], 'other platoon leader' => ['pl2'], 'section leader' => ['sl1'], 'far section leader' => ['sl3']]);

    it('runs every request as the person with the same red bar, naming the leader', function () {
        leaderStarts($this, $this->pl, $this->sl1);

        $this->actingAs($this->pl)->get('/home')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $this->sl1->id)
            ->where('auth.user.is_admin', false)
            ->where('actingAs', ['kind' => 'user', 'label' => 'SGT Vasquez.R', 'admin' => 'Hale.S']));
    });

    it('gives exactly the person\'s permissions: their section, not the leader\'s platoon', function () {
        leaderStarts($this, $this->pl, $this->sl1);

        // The platoon leader could view as the other section; the section leader cannot, so neither can the leader acting as them.
        $this->actingAs($this->pl)->post('/view-as', ['kind' => 'unit', 'id' => $this->section1->id])->assertSessionHasNoErrors();
        $this->actingAs($this->pl)->post('/view-as', ['kind' => 'unit', 'id' => $this->section2->id])->assertSessionHasErrors('view_as');
        $this->actingAs($this->pl)->get('/settings/audit-log')->assertForbidden();
    });

    it('records every entry written while acting with the leader as actor and the person as on behalf of', function () {
        leaderStarts($this, $this->pl, $this->sl1);

        $this->actingAs($this->pl)->post('/view-as', ['kind' => 'unit', 'id' => $this->section1->id])->assertSessionHasNoErrors();

        $entry = AuditLogEntry::where('action', 'view_as.started')->sole();
        expect($entry->actor_id)->toBe($this->pl->id)
            ->and($entry->on_behalf_of_id)->toBe($this->sl1->id);
    });

    it('stops acting, recorded with the leader as actor, and the leader is themselves again', function () {
        leaderStarts($this, $this->pl, $this->sl1);

        $this->actingAs($this->pl)->delete('/act-as')->assertRedirect('/home');

        $entry = AuditLogEntry::where('action', 'act_as.stopped')->sole();
        expect($entry->actor_id)->toBe($this->pl->id)
            ->and($entry->target_id)->toBe((string) $this->sl1->id)
            ->and($entry->on_behalf_of_id)->toBeNull()
            ->and(AuditLogEntry::orderBy('id')->pluck('action')->all())->toBe(['act_as.started', 'act_as.stopped']);
        $this->actingAs($this->pl)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->pl->id)->where('actingAs', null));
    });

    it('records acting as stopped when the leader signs out', function () {
        leaderStarts($this, $this->pl, $this->sl1);

        $this->actingAs($this->pl)->post('/logout')->assertRedirect('/login');

        expect(AuditLogEntry::where('action', 'act_as.stopped')->sole()->actor_id)->toBe($this->pl->id);
        $this->assertGuest();
    });

    it('refuses to chain: a leader acting as someone must stop before acting as another', function () {
        leaderStarts($this, $this->co, $this->pl);

        $this->actingAs($this->co)->post('/act-as', ['kind' => 'user', 'id' => $this->sl1->id])->assertSessionHasErrors('act_as');

        expect(AuditLogEntry::where('action', 'act_as.started')->count())->toBe(1);
        $this->actingAs($this->co)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->pl->id));
    });
});

describe('who a leader may never act as', function () {
    it('refuses', function (Closure $target) {
        $user = $target($this);

        leaderCannotStart($this, $this->pl, $user);
    })->with([
        'themselves' => [fn ($t) => $t->pl],
        'a peer on the same billet' => [fn ($t) => leaderPerson(910, 'Peer.P', 6100)],
        'a peer, the other platoon leader' => [fn ($t) => $t->pl2],
        'someone above them' => [fn ($t) => $t->co],
        'a leader in another platoon' => [fn ($t) => $t->sl3],
        'an Admin holding a billet below' => [fn ($t) => leaderPerson(911, 'Admin.A', 6200, null, ['is_admin' => true])],
        'a member with no billet' => [fn ($t) => leaderPerson(912, 'Trooper.T', null)],
        'a billet holder who left the Cav' => [fn ($t) => leaderPerson(913, 'Gone.G', 6201, TrooperStatus::Inactive)],
        'a user with no milpac link' => [fn ($t) => User::factory()->create(['cav_id' => null])],
        'a missing user' => [fn ($t) => User::factory()->make(['id' => 999999])],
        'a billet holder who also holds a grant beyond the leader' => [function ($t) {
            $someone = leaderPerson(914, 'Dual.D', 6201);
            PortalRoleGrant::factory()->create(['user_id' => $someone->id, 'unit_id' => $t->platoon2->id]);

            return $someone;
        }],
        'a billet holder who is also acting lead beyond the leader' => [function ($t) {
            $someone = leaderPerson(915, 'Lead.L', 6201);
            app(UnitActingLeadRepository::class)->assign($t->section3->id, Trooper::where('cav_id', 915)->value('id'), null);

            return $someone;
        }],
    ]);

    it('refuses a billet (unit) target: only an Admin may act as a billet', function () {
        $this->actingAs($this->pl)->post('/act-as', ['kind' => 'unit', 'id' => $this->section1->id])->assertForbidden();

        expect(AuditLogEntry::count())->toBe(0);
    });

    it('gives a staff grant no act-as rights, however wide', function () {
        $staff = leaderPerson(920, 'Staff.S', null);
        PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $this->company->id]);

        $this->actingAs($staff)->post('/act-as', ['kind' => 'user', 'id' => $this->sl1->id])->assertForbidden();
        $this->actingAs($staff)->getJson('/act-as/options')->assertForbidden();

        expect(AuditLogEntry::count())->toBe(0);
    });

    it('gives a staff grant nothing on top of a billet either: scope below comes from the billet alone', function () {
        PortalRoleGrant::factory()->create(['user_id' => $this->sl1->id, 'unit_id' => $this->company->id]);

        // A section leader commands nothing below them, whatever else they read.
        $this->actingAs($this->sl1)->post('/act-as', ['kind' => 'user', 'id' => $this->sl2->id])->assertForbidden();
        $this->actingAs($this->sl1)->post('/act-as', ['kind' => 'user', 'id' => $this->sl3->id])->assertForbidden();
    });

    it('refuses an unauthenticated request', function () {
        $this->post('/act-as', ['kind' => 'user', 'id' => $this->sl1->id])->assertRedirect('/login');
    });

    it('does not count an acting lead below a leader as a billet holder', function () {
        $member = leaderPerson(930, 'Member.M', null);
        app(UnitActingLeadRepository::class)->assign($this->section2->id, Trooper::where('cav_id', 930)->value('id'), null);

        leaderCannotStart($this, $this->pl, $member);
    });
});

describe('the scope check on every request', function () {
    it('ends when the person is moved to a billet outside the leader\'s scope', function () {
        leaderStarts($this, $this->pl, $this->sl1);
        Trooper::where('cav_id', 903)->update(['cav_position_id' => 6202]);

        $this->actingAs($this->pl)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->pl->id)->where('actingAs', null));
        // Dropped for good: it does not resume when the person moves back.
        Trooper::where('cav_id', 903)->update(['cav_position_id' => 6200]);
        $this->actingAs($this->pl)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->pl->id)->where('actingAs', null));
    });

    it('ends when the person leaves the Cav', function () {
        leaderStarts($this, $this->pl, $this->sl1);
        Trooper::where('cav_id', 903)->update(['status' => TrooperStatus::Inactive]);

        $this->actingAs($this->pl)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->pl->id)->where('actingAs', null));
    });

    it('ends when the person is promoted into the leader\'s own billet or above', function () {
        leaderStarts($this, $this->pl, $this->sl1);
        Trooper::where('cav_id', 903)->update(['cav_position_id' => 6100]);

        $this->actingAs($this->pl)->get('/home')->assertInertia(fn (Assert $page) => $page->where('actingAs', null));
    });

    it('ends when the person becomes an Admin', function () {
        leaderStarts($this, $this->pl, $this->sl1);
        $this->sl1->forceFill(['is_admin' => true])->save();

        $this->actingAs($this->pl)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->pl->id)->where('actingAs', null));
    });

    it('ends when the person gains a grant beyond the leader\'s scope', function () {
        leaderStarts($this, $this->pl, $this->sl1);
        PortalRoleGrant::factory()->create(['user_id' => $this->sl1->id, 'unit_id' => $this->platoon2->id]);

        $this->actingAs($this->pl)->get('/home')->assertInertia(fn (Assert $page) => $page->where('actingAs', null));
    });

    it('ends when the leader loses their billet', function () {
        leaderStarts($this, $this->pl, $this->sl1);
        Trooper::where('cav_id', 901)->update(['cav_position_id' => null]);

        $this->actingAs($this->pl)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->pl->id)->where('actingAs', null));
    });

    it('ends when the leader is moved to a unit that no longer contains the person', function () {
        leaderStarts($this, $this->pl, $this->sl1);
        Trooper::where('cav_id', 901)->update(['cav_position_id' => 6101]);

        $this->actingAs($this->pl)->get('/home')->assertInertia(fn (Assert $page) => $page->where('actingAs', null));
    });

    it('does not accept a stored choice that the leader never made through the check', function () {
        // A session forged to carry another leader's person: the stored admin id binds it to whoever started it.
        $this->actingAs($this->pl)->withSession(['act_as' => ['admin' => $this->pl2->id, 'kind' => 'user', 'id' => $this->sl3->id]])
            ->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->pl->id)->where('actingAs', null));

        $this->actingAs($this->pl)->withSession(['act_as' => ['admin' => $this->pl->id, 'kind' => 'user', 'id' => $this->sl3->id]])
            ->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->pl->id)->where('actingAs', null));
    });

    it('does not let a stored billet choice work for a non-Admin', function () {
        $this->actingAs($this->pl)->withSession(['act_as' => ['admin' => $this->pl->id, 'kind' => 'unit', 'id' => $this->section1->id]])
            ->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->pl->id)->where('actingAs', null));
    });

    it('records no entry for the drop itself', function () {
        leaderStarts($this, $this->pl, $this->sl1);
        Trooper::where('cav_id', 903)->update(['status' => TrooperStatus::Inactive]);

        $this->actingAs($this->pl)->get('/home');

        expect(AuditLogEntry::pluck('action')->all())->toBe(['act_as.started']);
    });
});

describe('the picker', function () {
    it('lists only serving billet holders strictly below the leader', function () {
        leaderPerson(940, 'Peer.P', 6100);
        leaderPerson(941, 'Gone.G', 6201, TrooperStatus::Inactive);
        leaderPerson(942, 'Admin.A', 6201, null, ['is_admin' => true]);
        leaderPerson(943, 'Trooper.T', null);
        User::factory()->create(['cav_id' => null, 'username' => 'Nolink.N']);

        $this->actingAs($this->pl)->getJson('/act-as/options')->assertOk()->assertExactJson([
            'people' => [
                ['id' => $this->sl2->id, 'label' => 'SGT Mercer.J', 'designation' => '2/1/A/1-7'],
                ['id' => $this->sl1->id, 'label' => 'SGT Vasquez.R', 'designation' => '1/1/A/1-7'],
            ],
        ]);
    });

    it('lists the whole chain below a commander, never themselves', function () {
        $ids = collect($this->actingAs($this->co)->getJson('/act-as/options')->assertOk()->json('people'))->pluck('id')->sort()->values()->all();

        expect($ids)->toBe(collect([$this->pl, $this->pl2, $this->sl1, $this->sl2, $this->sl3])->pluck('id')->sort()->values()->all());
    });

    it('offers nothing to a section leader, a plain member or a grant holder', function (string $who) {
        $this->actingAs($this->{$who})->getJson('/act-as/options')->assertForbidden();
    })->with(['section leader' => ['sl1']]);

    it('offers nothing to someone acting as a person, so a chain cannot start', function () {
        leaderStarts($this, $this->co, $this->pl);

        $this->actingAs($this->co)->getJson('/act-as/options')->assertForbidden();
    });

    it('shares canActAs with a leader who has people below, and not with anyone else', function () {
        $this->actingAs($this->pl)->get('/home')->assertInertia(fn (Assert $page) => $page->where('canActAs', true));
        $this->actingAs($this->sl1)->get('/home')->assertInertia(fn (Assert $page) => $page->where('canActAs', false));

        $staff = leaderPerson(950, 'Staff.S', null);
        PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $this->company->id]);
        $this->actingAs($staff)->get('/home')->assertInertia(fn (Assert $page) => $page->where('canActAs', false));
    });

    it('does not share canActAs while a leader is acting as a person, since a chain cannot start', function () {
        leaderStarts($this, $this->co, $this->pl);

        $this->actingAs($this->co)->get('/home')->assertInertia(fn (Assert $page) => $page->where('canActAs', false));
    });
});

describe('an Admin acting as a leader', function () {
    beforeEach(function () {
        Trooper::factory()->create(['cav_id' => 990, 'status' => TrooperStatus::Combat]);
        $this->admin = User::factory()->admin()->create(['username' => 'Root.R', 'cav_id' => 990]);
    });

    it('may act as someone below that leader, recorded with the Admin as actor', function () {
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->pl->id])->assertRedirect('/home');
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->sl1->id])->assertRedirect('/home');

        $this->actingAs($this->admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->sl1->id)->where('actingAs.admin', 'Root.R'));
        expect(AuditLogEntry::orderBy('id')->pluck('action')->all())->toBe(['act_as.started', 'act_as.stopped', 'act_as.started'])
            ->and(AuditLogEntry::where('action', 'act_as.started')->orderByDesc('id')->first()->actor_id)->toBe($this->admin->id);
    });

    it('cannot reach beyond that leader\'s scope', function (string $who) {
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->pl->id])->assertRedirect('/home');

        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->{$who}->id])->assertSessionHasErrors('act_as');

        $this->actingAs($this->admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->pl->id));
        expect(AuditLogEntry::where('action', 'act_as.started')->count())->toBe(1);
    })->with(['the other platoon\'s leader' => ['pl2'], 'a section in another platoon' => ['sl3'], 'the commander above' => ['co']]);

    it('cannot act as another Admin or a billet through the leader', function () {
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->pl->id])->assertRedirect('/home');
        $other = leaderPerson(960, 'Other.O', null, null, ['is_admin' => true]);

        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $other->id])->assertSessionHasErrors('act_as');
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'unit', 'id' => $this->section3->id])->assertSessionHasErrors('act_as');
    });

    it('is held to the leader\'s scope on every later request, not only when starting', function () {
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->pl->id]);
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->sl1->id]);

        // The leader is transferred away: what the Admin could reach through them no longer holds.
        Trooper::where('cav_id', 901)->update(['cav_position_id' => 6101]);

        $this->actingAs($this->admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->admin->id)->where('actingAs', null));
    });

    it('also ends when the person leaves the leader\'s scope', function () {
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->pl->id]);
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->sl1->id]);
        Trooper::where('cav_id', 903)->update(['cav_position_id' => 6202]);

        $this->actingAs($this->admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('actingAs', null));
    });

    it('cannot chain a second time', function () {
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->co->id]);
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->pl->id])->assertRedirect('/home');

        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->sl1->id])->assertSessionHasErrors('act_as');
        $this->actingAs($this->admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->pl->id));
    });

    it('stops with one stop and is the Admin again, who may then act as anyone', function () {
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->pl->id]);
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->sl1->id]);

        $this->actingAs($this->admin)->delete('/act-as')->assertRedirect('/home');
        $this->actingAs($this->admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->admin->id)->where('actingAs', null));

        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->sl3->id])->assertRedirect('/home');
    });

    it('acting as a billet may act as a person below that unit, never beyond it', function () {
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'unit', 'id' => $this->platoon->id])->assertRedirect('/home');

        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->sl3->id])->assertSessionHasErrors('act_as');
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->pl->id])->assertSessionHasErrors('act_as');
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->sl2->id])->assertRedirect('/home');

        $this->actingAs($this->admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->sl2->id));
    });
});
