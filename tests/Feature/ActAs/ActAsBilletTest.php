<?php

use App\Models\AuditLogEntry;
use App\Models\PlatoonTrainingTopic;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

// #379 follow-up: an Admin acts as a billet, a unit's leader with no person behind it (vacant or not). The request runs
// as a stand-in for the Admin whose only command is that unit; what they do is recorded in their own name, as that
// billet: "Hale.S acting as Section Leader 2/1/A/1-7".

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $this->week = '2026-10-03';

    $units = app(UnitRepository::class);
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', null);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $this->section = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->platoon->id);
    $this->sibling = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    Trooper::factory()->create(['primary_section_id' => $this->section->id, 'status' => TrooperStatus::Combat]);

    // The Admin holds a billet and a grant of their own, neither of which may leak into the one they act as.
    UnitCommandMapping::factory()->create(['unit_id' => $company->id, 'cav_position_id' => 7700]);
    Trooper::factory()->create(['cav_id' => 950, 'cav_position_id' => 7700, 'status' => TrooperStatus::Combat, 'username' => 'Hale.S', 'rank_short' => 'MSG']);
    $this->admin = User::factory()->admin()->create(['username' => 'Hale.S', 'cav_id' => 950]);
    PortalRoleGrant::factory()->create(['user_id' => $this->admin->id, 'unit_id' => $company->id]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function abActAsBillet(object $test, Unit $unit): void
{
    $test->actingAs($test->admin)->post('/act-as', ['kind' => 'unit', 'id' => $unit->id])->assertRedirect('/home');
}

it('lets an Admin act as a vacant billet, recorded against the unit with the Admin as actor', function () {
    abActAsBillet($this, $this->section);

    $entry = AuditLogEntry::where('action', 'act_as.started')->sole();
    expect($entry->actor_id)->toBe($this->admin->id)
        ->and($entry->target_type)->toBe('Unit')
        ->and($entry->target_id)->toBe((string) $this->section->id)
        ->and($entry->on_behalf_of_id)->toBeNull()
        ->and($entry->on_behalf_of_unit_id)->toBeNull();
});

it('runs every request as the billet: the red bar names it, and no Admin screens', function () {
    abActAsBillet($this, $this->section);

    $this->actingAs($this->admin)->get('/home')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.id', $this->admin->id)
        ->where('auth.user.is_admin', false)
        ->where('auth.profile', null)
        ->where('actingAs', ['kind' => 'unit', 'label' => 'Section Leader 2/1/A/1-7', 'admin' => 'Hale.S']));

    $this->actingAs($this->admin)->get('/settings/audit-log')->assertForbidden();
});

it('commands the billet\'s unit and nothing else: not the Admin\'s own billet, grant or a sibling', function () {
    abActAsBillet($this, $this->section);

    $this->actingAs($this->admin)->get("/reports/{$this->section->id}/{$this->week}")->assertOk();
    $this->actingAs($this->admin)->get("/reports/{$this->sibling->id}/{$this->week}")->assertForbidden();
    $this->actingAs($this->admin)->get("/reports/{$this->platoon->id}/{$this->week}")->assertForbidden();

    $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->sibling->id])->assertSessionHasErrors('view_as');
});

it('reaches down from a higher billet, as that unit\'s leader would', function () {
    abActAsBillet($this, $this->platoon);

    $this->actingAs($this->admin)->get("/reports/{$this->section->id}/{$this->week}")->assertOk();
    $this->actingAs($this->admin)->get("/reports/{$this->sibling->id}/{$this->week}")->assertOk();
});

it('records each change as the Admin acting as the billet, in the audit log and on the record', function () {
    abActAsBillet($this, $this->section);

    $this->actingAs($this->admin)->post("/reports/{$this->section->id}/{$this->week}/send", ['reason' => 'Weekly report', 'notes' => 'Billet vacant.'])
        ->assertSessionHasNoErrors();

    $report = WeeklyReport::query()->sole();
    expect($report->sent_by)->toBe($this->admin->id)
        ->and($report->sent_by_admin)->toBeNull()
        ->and($report->sent_as_unit)->toBe($this->section->id);

    $sent = AuditLogEntry::where('action', 'weekly_report.sent')->sole();
    expect($sent->actor_id)->toBe($this->admin->id)
        ->and($sent->on_behalf_of_id)->toBeNull()
        ->and($sent->on_behalf_of_unit_id)->toBe($this->section->id);

    $this->actingAs($this->admin)->get("/reports/{$this->section->id}/{$this->week}")
        ->assertInertia(fn (Assert $page) => $page->where('report.sentBy', 'MSG Hale.S acting as Section Leader 2/1/A/1-7'));
});

it('names the billet on a topic set while holding it', function () {
    abActAsBillet($this, $this->platoon);

    $this->actingAs($this->admin)->put("/reports/{$this->platoon->id}/{$this->week}/topic", ['subject' => 'Room clearing', 'enforced' => true])
        ->assertSessionHasNoErrors();

    expect(PlatoonTrainingTopic::query()->sole()->set_as_unit)->toBe($this->platoon->id);
    $this->actingAs($this->admin)->get("/reports/{$this->section->id}/{$this->week}")
        ->assertInertia(fn (Assert $page) => $page->where('topic.setBy', 'MSG Hale.S acting as Platoon Leader 1/A/1-7'));
});

it('stops, recorded as the Admin\'s own, and the Admin is an Admin again', function () {
    abActAsBillet($this, $this->section);
    $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section->id]);

    $this->actingAs($this->admin)->delete('/act-as')->assertRedirect('/home');

    expect(AuditLogEntry::orderBy('id')->get(['action', 'on_behalf_of_unit_id'])->map(fn ($e) => [$e->action, $e->on_behalf_of_unit_id])->all())->toBe([
        ['act_as.started', null],
        ['view_as.started', $this->section->id],
        ['view_as.stopped', $this->section->id],
        ['act_as.stopped', null],
    ]);
    $this->actingAs($this->admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.is_admin', true)->where('actingAs', null)->where('viewAs', null));
    $this->actingAs($this->admin)->get('/settings/audit-log')->assertOk();
});

it('stops applying once the unit is deleted or the Admin loses the flag', function (string $change) {
    abActAsBillet($this, $this->sibling);

    $change === 'unit deleted' ? $this->sibling->delete() : $this->admin->forceFill(['is_admin' => false])->save();

    $this->actingAs($this->admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('actingAs', null));
})->with(['unit deleted', 'admin loses flag']);

it('keeps the Admin signed in while acting as a billet, though the stand-in has no milpac', function () {
    $noMilpac = User::factory()->admin()->create(['cav_id' => null]);
    $this->actingAs($noMilpac)->post('/act-as', ['kind' => 'unit', 'id' => $this->section->id]);

    $this->actingAs($noMilpac)->get('/home')->assertOk()->assertInertia(fn (Assert $page) => $page->where('actingAs.kind', 'unit'));
    $this->assertAuthenticatedAs($noMilpac);
});

it('never writes the stand-in back over the Admin\'s account', function () {
    abActAsBillet($this, $this->section);
    $this->actingAs($this->admin)->get('/home');

    expect($this->admin->fresh())->is_admin->toBeTrue()->cav_id->toBe(950);
});

it('refuses a unit that does not exist, and a non-Admin outright', function () {
    $this->actingAs($this->admin)->post('/act-as', ['kind' => 'unit', 'id' => 999999])->assertSessionHasErrors('act_as');

    Trooper::factory()->create(['cav_id' => 960, 'status' => TrooperStatus::Combat]);
    $leader = User::factory()->create(['cav_id' => 960]);
    $this->actingAs($leader)->post('/act-as', ['kind' => 'unit', 'id' => $this->section->id])->assertForbidden();

    expect(AuditLogEntry::count())->toBe(0);
});

it('shows on the audit log who acted as which billet', function () {
    abActAsBillet($this, $this->section);
    $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section->id]);
    $this->actingAs($this->admin)->delete('/act-as');

    $this->actingAs($this->admin)->get('/settings/audit-log')->assertInertia(fn (Assert $page) => $page
        ->where('entries.data', fn ($entries) => collect($entries)->contains(fn ($e) => $e['action'] === 'view_as.started' && $e['onBehalfOf'] === 'Section Leader 2/1/A/1-7')));
});
