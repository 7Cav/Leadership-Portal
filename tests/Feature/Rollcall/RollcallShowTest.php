<?php

use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\Loa;
use App\Models\PortalRoleGrant;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

// 3.7 / AD-8, AD-11, AD-14, AD-20, AD-21: the rollcall page reads one section's roster and marks
// for a week, gated by the ScopeResolver.

beforeEach(function () {
    // Thursday 08Oct26 16:20Z — inside the Cav week that opened Saturday 03Oct26.
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $this->week = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');

    $this->platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'name' => '1st Platoon', 'callsign' => '1/A/1-7', 'path' => '900/']);
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id, 'name' => '2nd Section', 'callsign' => '2/1/A/1-7', 'path' => '900/901/']);
    $this->sibling = Unit::factory()->create(['type' => UnitType::Section, 'parent_id' => $this->platoon->id, 'name' => '3rd Section', 'callsign' => '3/1/A/1-7', 'path' => '900/902/']);

    $this->leader = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $this->leader->id, 'unit_id' => $this->section->id]);
});

function rollcallTrooper(Unit $section, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

it('lists the troopers placed in the section, each exactly once, by their synced forum identity', function () {
    $named = rollcallTrooper($this->section, ['rank' => 'Sergeant', 'rank_short' => 'SGT', 'username' => 'Vasquez.R']);
    $unnamed = rollcallTrooper($this->section, ['rank_short' => 'PVT', 'username' => null]);
    rollcallTrooper($this->sibling);
    rollcallTrooper($this->section, ['status' => TrooperStatus::Inactive]);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Rollcall')
            ->where('section.designation', '2/1/A/1-7')
            ->where('section.platoon', '1/A/1-7')
            ->has('troopers', 2)
            ->where('troopers.0.id', $named->id)
            ->where('troopers.0.name', 'Vasquez.R')
            ->where('troopers.0.rank', 'Sergeant')
            ->where('troopers.0.rankShort', 'SGT')
            ->where('troopers.0.displayName', 'SGT Vasquez.R')
            ->where('troopers.1.id', $unnamed->id)
            ->where('troopers.1.name', null)
            ->where('troopers.1.displayName', "PVT #{$unnamed->cav_id}")
            ->where('troopers.1.rollcall', 'unsigned')
            ->where('troopers.1.status', null));
});

it('loads this week\'s marks with their source', function () {
    $trooper = rollcallTrooper($this->section);
    $practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->week]);
    Attendance::factory()->create([
        'trooper_id' => $trooper->id, 'section_practice_id' => $practice->id,
        'status' => AttendanceStatus::Missed, 'source' => AttendanceSource::Manual, 'rollcall' => RollcallSignature::CannotAttend,
    ]);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page
            ->where('troopers.0.status', 'missed')
            ->where('troopers.0.source', 'manual')
            ->where('troopers.0.rollcall', 'cannot_attend')
            ->whereNot('practice.lastMarkedAt', null));
});

it('offers last week\'s practice marks as the pre-fill', function () {
    $trooper = rollcallTrooper($this->section);
    $signedOnly = rollcallTrooper($this->section);
    $prior = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->week->subWeek()]);
    Attendance::factory()->create(['trooper_id' => $trooper->id, 'section_practice_id' => $prior->id, 'status' => AttendanceStatus::Makeup]);
    Attendance::factory()->create(['trooper_id' => $signedOnly->id, 'section_practice_id' => $prior->id, 'status' => null, 'source' => null]);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page
            ->where('priorWeekMarked', true)
            ->where('troopers', fn ($rows) => collect($rows)->pluck('priorStatus', 'id')->sortKeys()->all() === [
                $trooper->id => 'makeup',
                $signedOnly->id => null,
            ]));
});

it('pre-fills nothing when last week has no practice marks', function () {
    rollcallTrooper($this->section);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page
            ->where('priorWeekMarked', false)
            ->where('troopers.0.priorStatus', null));
});

it('renders an empty roster as a page, not an error', function () {
    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('troopers', 0)->where('priorWeekMarked', false)->where('atRisk', []));
});

it('flags at-risk troopers from the shared KPI service', function () {
    $trooper = rollcallTrooper($this->section);
    foreach ([0, 1] as $weeksAgo) {
        $practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->week->subWeeks($weeksAgo)]);
        Attendance::factory()->create(['trooper_id' => $trooper->id, 'section_practice_id' => $practice->id, 'status' => AttendanceStatus::Missed]);
    }

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page->where('atRisk', [['trooperId' => $trooper->id, 'missed' => 2, 'windowWeeks' => 4]]));
});

it('shows the roster source and its last successful sync (AD-20)', function () {
    SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Success, 'finished_at' => '2026-10-08 09:14:00']);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page
            ->where('roster.source', 'milpac')
            ->where('roster.syncedAt', '2026-10-08T09:14:00+00:00'));
});

it('sends each trooper\'s leave for the week from the one coverage definition, with the LOA source (3.4, AD-20)', function () {
    $covered = rollcallTrooper($this->section, ['username' => 'Alpha.A']);
    $review = rollcallTrooper($this->section, ['username' => 'Bravo.B']);
    $clear = rollcallTrooper($this->section, ['username' => 'Charlie.C']);
    $later = rollcallTrooper($this->section, ['username' => 'Delta.D']);
    Loa::factory()->create(['trooper_id' => $covered->id, 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-10']);
    Loa::factory()->needsReview()->create(['trooper_id' => $review->id, 'posted_at' => '2026-10-05 10:00:00', 'review_reason' => 'No end date found.']);
    // A leave that starts after this week covers nothing in it.
    Loa::factory()->create(['trooper_id' => $later->id, 'starts_at' => '2026-10-12', 'ends_at' => '2026-10-20']);
    SyncRun::factory()->create(['source' => SyncRun::SOURCE_LOA, 'status' => SyncRunStatus::Success, 'finished_at' => '2026-10-08 15:00:00']);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page
            ->where('troopers.0.id', $covered->id)
            ->where('troopers.0.loa.state', 'covered')
            ->where('troopers.0.loa.startsAt', '2026-10-01')
            ->where('troopers.0.loa.endsAt', '2026-10-10')
            ->where('troopers.0.loa.endsInDays', 2)
            // Nothing is stored for them: the status stays the stored one, and the screen derives `loa` from `loa`.
            ->where('troopers.0.status', null)
            ->where('troopers.1.loa.state', 'needs_review')
            ->where('troopers.1.loa.startsAt', null)
            ->where('troopers.1.loa.reviewReason', 'No end date found.')
            ->where('troopers.2.id', $clear->id)
            ->where('troopers.2.loa', null)
            ->where('troopers.3.loa', null)
            ->where('loaSync.source', 'Forum thread')
            ->where('loaSync.lastSyncedAt', '2026-10-08T15:00:00+00:00')
            ->where('loaSync.stale', false));
});

it('sends coverage for the week asked about, not the current one', function () {
    $trooper = rollcallTrooper($this->section);
    Loa::factory()->create(['trooper_id' => $trooper->id, 'starts_at' => '2026-09-21', 'ends_at' => '2026-09-25']);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?week=2026-09-22")
        ->assertInertia(fn ($page) => $page->where('troopers.0.loa.state', 'covered')->where('troopers.0.loa.ended', true));
    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page->where('troopers.0.loa', null));
});

it('opens the week containing the requested date, and knows its neighbours', function () {
    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?week=2026-09-30")
        ->assertInertia(fn ($page) => $page
            ->where('week.date', '2026-09-26')
            ->where('week.isCurrent', false)
            ->where('week.previous', '2026-09-19')
            ->where('week.next', '2026-10-03')
            ->where('week.closes', '2026-10-02T23:59:00+00:00'));
});

it('defaults to the current week and offers no next week', function () {
    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page
            ->where('week.date', '2026-10-03')
            ->where('week.isCurrent', true)
            ->where('week.next', null));
});

it('never serves a future week', function () {
    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?week=2026-10-10")
        ->assertRedirect("/rollcall/{$this->section->id}");
});

it('rejects a malformed week', function () {
    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?week=next-friday")
        ->assertSessionHasErrors('week');
});

it('refuses a section outside the viewer\'s scope server-side (AD-8)', function () {
    $this->actingAs($this->leader)->get("/rollcall/{$this->sibling->id}")->assertForbidden();
    $this->actingAs(User::factory()->serving()->create())->get("/rollcall/{$this->section->id}")->assertForbidden();
});

it('403s a unit nobody can scope (it does not exist) and 404s a unit that is not a section', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/rollcall/999999')->assertForbidden();
    $this->actingAs($admin)->get("/rollcall/{$this->platoon->id}")->assertNotFound();
});

it('lands a section leader on the section their billet commands', function () {
    $leader = User::factory()->serving()->create(['cav_id' => 4242]);
    Trooper::factory()->create(['cav_id' => 4242, 'cav_position_id' => 77]);
    UnitCommandMapping::factory()->create(['unit_id' => $this->sibling->id, 'cav_position_id' => 77]);

    $this->actingAs($leader)->get('/rollcall?week=2026-09-30')
        ->assertRedirect("/rollcall/{$this->sibling->id}?week=2026-09-26");
});

it('lands a user whose scope holds a single section on it', function () {
    $this->actingAs($this->leader)->get('/rollcall')->assertRedirect("/rollcall/{$this->section->id}");
});

it('gives anyone else a picker of the sections in their scope, grouped by platoon', function () {
    $platoonLeader = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $platoonLeader->id, 'unit_id' => $this->platoon->id]);

    $this->actingAs($platoonLeader)->get('/rollcall')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Rollcall')
            ->where('section', null)
            ->where('sections', [
                ['id' => $this->section->id, 'designation' => '2/1/A/1-7', 'name' => '2nd Section', 'platoon' => '1/A/1-7'],
                ['id' => $this->sibling->id, 'designation' => '3/1/A/1-7', 'name' => '3rd Section', 'platoon' => '1/A/1-7'],
            ]));
});

it('gives a user with no sections in scope an empty picker', function () {
    $this->actingAs(User::factory()->serving()->create())->get('/rollcall')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('section', null)->where('sections', []));
});

it('requires authentication', function () {
    $this->get('/rollcall')->assertRedirect(route('login'));
});
