<?php

use App\Authorization\ViewAs;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Models\AuditLogEntry;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Inertia\Testing\AssertableInertia as Assert;

// #379: an Admin acts as another person with exactly that person's permissions, writes included, under a red bar.
// Every entry written while acting names the Admin as actor and the person as on_behalf_of; start and stop are logged.

beforeEach(function () {
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->section = $repo->create(UnitType::Section, '1st Section', '1/1/A/1-7', $regiment->id);
    $this->other = $repo->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $regiment->id);

    UnitCommandMapping::factory()->create(['unit_id' => $this->section->id, 'cav_position_id' => 5900]);
    Trooper::factory()->create(['cav_id' => 901, 'cav_position_id' => 5900, 'status' => TrooperStatus::Combat, 'rank' => 'SGT']);
    $this->leader = User::factory()->create(['cav_id' => 901, 'username' => 'Vasquez.R']);

    // The Admin holds a milpac too, so losing the flag leaves them signed in as a plain member.
    Trooper::factory()->create(['cav_id' => 950, 'status' => TrooperStatus::Combat]);
    $this->admin = User::factory()->admin()->create(['username' => 'Hale.S', 'cav_id' => 950]);
});

// Each request signs the Admin in afresh, as a real request does: the session is the Admin's, and only the request's
// user is swapped for the person acted as. A test's app instance would otherwise carry that swap to the next request.
function startActing(object $test, ?User $as = null): void
{
    $test->actingAs($test->admin)->post('/act-as', ['kind' => 'user', 'id' => ($as ?? $test->leader)->id])->assertRedirect('/home');
}

it('lets an Admin act as a person, recorded with the Admin as actor', function () {
    startActing($this);

    $entry = AuditLogEntry::where('action', 'act_as.started')->sole();
    expect($entry->actor_id)->toBe($this->admin->id)
        ->and($entry->target_type)->toBe('User')
        ->and($entry->target_id)->toBe((string) $this->leader->id)
        ->and($entry->on_behalf_of_id)->toBeNull();
});

it('runs every request as the person: their identity, the red bar, and no Admin screens', function () {
    startActing($this);

    $this->actingAs($this->admin)->get('/home')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.id', $this->leader->id)
        ->where('auth.user.is_admin', false)
        ->where('auth.profile', null)
        ->where('actingAs', ['kind' => 'user', 'label' => 'SGT Vasquez.R', 'admin' => 'Hale.S']));

    $this->actingAs($this->admin)->get('/settings/audit-log')->assertForbidden();
});

it('writes as the person, inside their scope only, recorded as the Admin acting for them', function () {
    startActing($this);

    $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section->id])->assertSessionHasNoErrors();
    $entry = AuditLogEntry::where('action', 'view_as.started')->sole();
    expect($entry->actor_id)->toBe($this->admin->id)
        ->and($entry->on_behalf_of_id)->toBe($this->leader->id);

    // The Admin could view as the other section; the leader cannot, so neither can the Admin acting as them.
    $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->other->id])->assertSessionHasErrors('view_as');
});

it('stops acting, recorded with the Admin as actor, and the Admin is themselves again', function () {
    startActing($this);

    $this->actingAs($this->admin)->delete('/act-as')->assertRedirect('/home');

    $entry = AuditLogEntry::where('action', 'act_as.stopped')->sole();
    expect($entry->actor_id)->toBe($this->admin->id)
        ->and($entry->target_id)->toBe((string) $this->leader->id)
        ->and($entry->on_behalf_of_id)->toBeNull();

    $this->actingAs($this->admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->admin->id)->where('actingAs', null));
    $this->actingAs($this->admin)->get('/settings/audit-log')->assertOk();
});

it('ends a preview opened while acting when acting stops, recorded as the Admin acting for them', function () {
    startActing($this);
    $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section->id]);

    $this->actingAs($this->admin)->delete('/act-as');

    $stopped = AuditLogEntry::where('action', 'view_as.stopped')->sole();
    expect($stopped->actor_id)->toBe($this->admin->id)
        ->and($stopped->on_behalf_of_id)->toBe($this->leader->id)
        ->and(AuditLogEntry::orderBy('id')->pluck('action')->all())->toBe(['act_as.started', 'view_as.started', 'view_as.stopped', 'act_as.stopped']);

    startActing($this);
    $this->actingAs($this->admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('viewAs', null));
});

it('records nothing on anyone\'s behalf once the Admin is themselves again', function () {
    startActing($this);
    $this->actingAs($this->admin)->delete('/act-as');

    $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->other->id])->assertSessionHasNoErrors();

    expect(AuditLogEntry::where('action', 'view_as.started')->sole()->on_behalf_of_id)->toBeNull();
});

it('switches straight to another person, recording the first as stopped', function () {
    Trooper::factory()->create(['cav_id' => 902, 'status' => TrooperStatus::Combat]);
    $second = User::factory()->create(['cav_id' => 902]);
    startActing($this);

    startActing($this, $second);

    expect(AuditLogEntry::orderBy('id')->pluck('action')->all())->toBe(['act_as.started', 'act_as.stopped', 'act_as.started'])
        ->and(AuditLogEntry::whereNotNull('on_behalf_of_id')->count())->toBe(0);
    $this->actingAs($this->admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $second->id));
});

it('refuses the Admin themselves, someone who could not sign in, and a missing user', function (Closure $target) {
    $id = $target($this);

    $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $id])->assertSessionHasErrors('act_as');

    expect(AuditLogEntry::count())->toBe(0);
    $this->actingAs($this->admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->admin->id)->where('actingAs', null));
})->with([
    'themselves' => [fn ($t) => $t->admin->id],
    'no milpac link' => [fn ($t) => User::factory()->create(['cav_id' => null])->id],
    'left the Cav' => [function ($t) {
        Trooper::factory()->create(['cav_id' => 903, 'status' => TrooperStatus::Inactive]);

        return User::factory()->create(['cav_id' => 903])->id;
    }],
    'missing' => [fn ($t) => 999999],
]);

it('refuses a non-Admin outright', function () {
    $this->actingAs($this->leader)->post('/act-as', ['kind' => 'user', 'id' => $this->admin->id])->assertForbidden();

    expect(AuditLogEntry::count())->toBe(0);
});

it('stops applying when the Admin loses the flag', function (string $change) {
    startActing($this);

    $this->admin->forceFill(['is_admin' => false])->save();

    $this->actingAs($this->admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->admin->id)->where('actingAs', null));
})->with(['admin loses flag']);

it('acts as another Admin without their Admin rights: their billet only, recorded as acting for them', function () {
    $this->leader->forceFill(['is_admin' => true])->save();
    startActing($this);

    $this->actingAs($this->admin)->get('/home')->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.id', $this->leader->id)
        ->where('auth.user.is_admin', false)
        ->where('actingAs.label', 'SGT Vasquez.R'));
    $this->actingAs($this->admin)->get('/settings/audit-log')->assertForbidden();

    // Their section, as its leader; never the rest of the tree an Admin reads.
    $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section->id])->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->other->id])->assertSessionHasErrors('view_as');

    expect(AuditLogEntry::where('action', 'view_as.started')->sole()->on_behalf_of_id)->toBe($this->leader->id)
        ->and($this->leader->fresh()->is_admin)->toBeTrue();
});

it('keeps acting as a person who becomes an Admin meanwhile, still without Admin rights', function () {
    startActing($this);
    $this->leader->forceFill(['is_admin' => true])->save();

    $this->actingAs($this->admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->leader->id)->where('auth.user.is_admin', false));
});

it('ends a view-as preview the Admin had open, and is not blocked by its read-only guard', function () {
    app(ViewAs::class)->enter($this->admin, new ViewAsTarget(ViewAsKind::User, $this->leader->id));

    startActing($this);

    expect(AuditLogEntry::orderBy('id')->pluck('action')->all())->toBe(['view_as.stopped', 'act_as.started']);
    $this->actingAs($this->admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('viewAs', null)->whereNot('actingAs', null));
});

it('records acting as stopped when the Admin signs out', function () {
    startActing($this);

    $this->actingAs($this->admin)->post('/logout')->assertRedirect('/login');

    expect(AuditLogEntry::where('action', 'act_as.stopped')->sole()->actor_id)->toBe($this->admin->id);
    $this->assertGuest();
});

it('shows on the audit log who acted for whom', function () {
    startActing($this);
    $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section->id]);
    $this->actingAs($this->admin)->delete('/act-as');

    $this->actingAs($this->admin)->get('/settings/audit-log')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('entries.data', fn ($entries) => collect($entries)->contains(fn ($e) => $e['action'] === 'view_as.started' && $e['actor'] === 'Hale.S' && $e['onBehalfOf'] === 'Vasquez.R')
            && collect($entries)->contains(fn ($e) => $e['action'] === 'act_as.started' && $e['onBehalfOf'] === null)));
});
