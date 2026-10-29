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
use App\Models\WeeklyReport;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

it('sends each trooper\'s synced rank insignia for the template\'s {{rank_icon}}, null when Cav has none', function () {
    rollcallTrooper($this->section, ['username' => 'Adams.P', 'rank_image_url' => 'https://7cav.us/data/roster_ranks/0/12.jpg']);
    rollcallTrooper($this->section, ['username' => 'Baker.Q', 'rank_image_url' => null]);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page
            ->where('troopers.0.rankIcon', 'https://7cav.us/data/roster_ranks/0/12.jpg')
            ->where('troopers.1.rankIcon', null));
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

it('gives the picker to anyone who asks for it with ?pick, instead of landing them on a section', function () {
    $leader = User::factory()->serving()->create(['cav_id' => 4242]);
    Trooper::factory()->create(['cav_id' => 4242, 'cav_position_id' => 77]);
    UnitCommandMapping::factory()->create(['unit_id' => $this->sibling->id, 'cav_position_id' => 77]);
    PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $this->section->id]);

    $this->actingAs($leader)->get('/rollcall?pick=1&week=2026-09-30')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Rollcall')
            ->where('section', null)
            ->where('week.date', '2026-09-26')
            ->has('sections', 2));

    $this->actingAs($this->leader)->get('/rollcall?pick=1')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('section', null)->has('sections', 1));
});

it('remembers the section a user opens, per user, but not during a view-as preview', function () {
    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertOk()
        ->assertCookie("rollcall_section_{$this->leader->id}", (string) $this->section->id);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->platoon->id]);
    $this->actingAs($admin)->get("/rollcall/{$this->sibling->id}")
        ->assertOk()
        ->assertCookieMissing("rollcall_section_{$admin->id}");
});

it('lands a leader on the section they last opened, ahead of the one their billet commands', function () {
    $leader = User::factory()->serving()->create(['cav_id' => 4242]);
    Trooper::factory()->create(['cav_id' => 4242, 'cav_position_id' => 77]);
    UnitCommandMapping::factory()->create(['unit_id' => $this->sibling->id, 'cav_position_id' => 77]);
    PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $this->section->id]);

    $this->actingAs($leader)->withCookie("rollcall_section_{$leader->id}", (string) $this->section->id)
        ->get('/rollcall?week=2026-09-30')
        ->assertRedirect("/rollcall/{$this->section->id}?week=2026-09-26");
});

it('ignores a remembered section outside the scope, or remembered for another user, and for ?pick', function () {
    $leader = User::factory()->serving()->create(['cav_id' => 4242]);
    Trooper::factory()->create(['cav_id' => 4242, 'cav_position_id' => 77]);
    UnitCommandMapping::factory()->create(['unit_id' => $this->sibling->id, 'cav_position_id' => 77]);
    PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $this->section->id]);
    $outside = Unit::factory()->create(['type' => UnitType::Section, 'name' => '1st Section', 'path' => '800/']);

    $this->actingAs($leader)->withCookie("rollcall_section_{$leader->id}", (string) $outside->id)
        ->get('/rollcall')->assertRedirect("/rollcall/{$this->sibling->id}");
    $this->actingAs($leader)->withCookie("rollcall_section_{$this->leader->id}", (string) $this->section->id)
        ->get('/rollcall')->assertRedirect("/rollcall/{$this->sibling->id}");

    $this->actingAs($leader)->withCookie("rollcall_section_{$leader->id}", (string) $this->section->id)
        ->get('/rollcall?pick=1')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('section', null)->has('sections', 2));
});

it('does not follow the remembered section during a view-as preview', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->platoon->id]);

    $this->actingAs($admin)->withCookie("rollcall_section_{$admin->id}", (string) $this->sibling->id)
        ->get('/rollcall')
        ->assertRedirect("/rollcall/{$this->section->id}");
});

it('lands anyone with several sections and no remembered one on the first section of the first platoon', function () {
    $platoonLeader = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $platoonLeader->id, 'unit_id' => $this->platoon->id]);

    $this->actingAs($platoonLeader)->get('/rollcall?week=2026-09-30')
        ->assertRedirect("/rollcall/{$this->section->id}?week=2026-09-26");
});

it('picks that first section by designation, not by the order the units were created in', function () {
    // Bravo's 2nd Platoon came first, then its 1st Platoon with the 2nd Section before the 1st.
    $units = app(UnitRepository::class);
    $company = $units->create(UnitType::Company, 'Bravo Company', 'B/1-7', null);
    $second = $units->create(UnitType::Platoon, '2nd Platoon', '2/B/1-7', $company->id);
    $units->create(UnitType::Section, '1st Section', '1/2/B/1-7', $second->id);
    $first = $units->create(UnitType::Platoon, '1st Platoon', '1/B/1-7', $company->id);
    $units->create(UnitType::Section, '2nd Section', '2/1/B/1-7', $first->id);
    $wanted = $units->create(UnitType::Section, '1st Section', '1/1/B/1-7', $first->id);
    $commander = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $commander->id, 'unit_id' => $company->id]);

    $this->actingAs($commander)->get('/rollcall')->assertRedirect("/rollcall/{$wanted->id}");
});

it('gives anyone else a picker of the sections in their scope, grouped by platoon', function () {
    $platoonLeader = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $platoonLeader->id, 'unit_id' => $this->platoon->id]);

    $this->actingAs($platoonLeader)->get('/rollcall?pick=1')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Rollcall')
            ->where('section', null)
            ->where('sections', [
                ['id' => $this->section->id, 'designation' => '2/1/A/1-7', 'name' => '2nd Section', 'platoon' => '1/A/1-7', 'label' => null, 'progress' => EMPTY_PROGRESS],
                ['id' => $this->sibling->id, 'designation' => '3/1/A/1-7', 'name' => '3rd Section', 'platoon' => '1/A/1-7', 'label' => null, 'progress' => EMPTY_PROGRESS],
            ]));
});

const EMPTY_PROGRESS = ['strength' => 0, 'onLoa' => 0, 'unsigned' => 0, 'unmarked' => 0, 'started' => false, 'posted' => false];

it('tells the picker where each section stands for the week shown', function () {
    $platoonLeader = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $platoonLeader->id, 'unit_id' => $this->platoon->id]);
    [$signed, $marked, $onLoa] = [rollcallTrooper($this->section), rollcallTrooper($this->section), rollcallTrooper($this->section)];
    rollcallTrooper($this->section);
    rollcallTrooper($this->sibling);
    $practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->week]);
    Attendance::factory()->create(['trooper_id' => $signed->id, 'section_practice_id' => $practice->id, 'rollcall' => RollcallSignature::WillAttend, 'status' => null, 'source' => null]);
    Attendance::factory()->create(['trooper_id' => $marked->id, 'section_practice_id' => $practice->id, 'rollcall' => RollcallSignature::Unsigned, 'status' => AttendanceStatus::Present]);
    Attendance::factory()->create(['trooper_id' => $onLoa->id, 'section_practice_id' => $practice->id, 'rollcall' => RollcallSignature::Unsigned, 'status' => AttendanceStatus::Loa]);
    $lastWeek = SectionPractice::factory()->create(['section_id' => $this->sibling->id, 'week_start' => $this->week->subWeek()]);
    $lastWeek->forceFill(['rollcall_posted_at' => '2026-10-01 20:00:00'])->save();

    $this->actingAs($platoonLeader)->get('/rollcall?pick=1')
        ->assertInertia(fn ($page) => $page
            ->where('sections.0.progress', ['strength' => 4, 'onLoa' => 1, 'unsigned' => 2, 'unmarked' => 2, 'started' => true, 'posted' => false])
            ->where('sections.1.progress', ['strength' => 1, 'onLoa' => 0, 'unsigned' => 1, 'unmarked' => 1, 'started' => false, 'posted' => false]));

    $this->actingAs($platoonLeader)->get('/rollcall?pick=1&week=2026-09-26')
        ->assertInertia(fn ($page) => $page->where('sections.1.progress.posted', true)->where('sections.0.progress.started', false));

    // The switcher on a section's page lists the same states.
    $this->actingAs($platoonLeader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page->where('sections.0.progress.unsigned', 2));
});

it('counts a trooper whose synced leave covers the week as on LOA in the picker, not unsigned or unmarked', function () {
    $platoonLeader = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $platoonLeader->id, 'unit_id' => $this->platoon->id]);
    $present = rollcallTrooper($this->section);
    $away = rollcallTrooper($this->section);
    $needsReview = rollcallTrooper($this->section);
    // Leave comes from the LOA sync (4.4), never from a stored `loa` mark: the screen reads it through `LoaCoverage`.
    Loa::factory()->create(['trooper_id' => $away->id, 'starts_at' => '2026-10-03', 'ends_at' => '2026-10-16']);
    Loa::factory()->needsReview()->create(['trooper_id' => $needsReview->id, 'posted_at' => '2026-10-05 12:00:00']);
    $practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->week]);
    Attendance::factory()->create(['trooper_id' => $present->id, 'section_practice_id' => $practice->id, 'rollcall' => RollcallSignature::WillAttend, 'status' => AttendanceStatus::Present]);

    $this->actingAs($platoonLeader)->get('/rollcall?pick=1')
        ->assertInertia(fn ($page) => $page
            ->where('sections.0.progress', ['strength' => 3, 'onLoa' => 1, 'unsigned' => 1, 'unmarked' => 1, 'started' => true, 'posted' => false]));
});

it('gives a user with no sections in scope an empty picker', function () {
    $this->actingAs(User::factory()->serving()->create())->get('/rollcall')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('section', null)->where('sections', []));
});

it("gives the deadline banner the shown week's report: when it is due, whether it is sent, and where it is", function () {
    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page->where('report', [
            'due' => '2026-10-10T23:59:00+00:00',
            'overdueUntil' => '2026-10-31T00:00:00+00:00',
            'sent' => false,
            'href' => route('reports.show', ['unit' => $this->section->id, 'week' => '2026-10-03']),
        ]));

    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $this->week->subWeek()]);

    // On the due Saturday the new week is asked for by date: the bare URL opens the week whose report is still due.
    $this->travelTo(CarbonImmutable::parse('2026-10-10 20:00:00', 'UTC'));
    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?week=2026-10-10")
        ->assertInertia(fn ($page) => $page->where('week.date', '2026-10-10')->where('report.due', '2026-10-17T23:59:00+00:00'));
    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?week=2026-10-03")
        ->assertInertia(fn ($page) => $page->where('report.due', '2026-10-10T23:59:00+00:00')->where('report.sent', false));
    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?week=2026-09-26")
        ->assertInertia(fn ($page) => $page->where('report.due', '2026-10-03T23:59:00+00:00')->where('report.sent', true));

    // The overdue line ends when the week leaves the KPI window: the current week and the 3 before it.
    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?week=2026-09-19")
        ->assertInertia(fn ($page) => $page->where('report.overdueUntil', '2026-10-17T00:00:00+00:00'));
});

it('requires authentication', function () {
    $this->get('/rollcall')->assertRedirect(route('login'));
});

it('marks the week locked only when the section\'s real report for that week has been sent (AD-12)', function () {
    rollcallTrooper($this->section);
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $this->week]);
    WeeklyReport::factory()->create(['unit_id' => $this->section->id, 'week_start' => $this->week->subWeek()]);
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $this->week->subWeeks(2), 'is_demo' => true]);
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->sibling->id, 'week_start' => $this->week->subWeeks(3)]);
    $locked = fn (string $week, bool $expected) => $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?week={$week}")
        ->assertOk()->assertInertia(fn ($page) => $page->where('locked', $expected));

    $locked('2026-10-03', true);   // sent
    $locked('2026-09-26', false);  // a draft
    $locked('2026-09-19', false);  // a demo sent report (#115)
    $locked('2026-09-12', false);  // another section's sent report
    $locked('2026-09-05', false);  // no report at all
});

it('sends where each of the last four past weeks stands, so the week navigation can flag unfinished ones', function () {
    $a = rollcallTrooper($this->section);
    $b = rollcallTrooper($this->section);
    $departed = rollcallTrooper($this->section, ['status' => TrooperStatus::Inactive]);
    $moved = rollcallTrooper($this->sibling);
    $practice = fn (int $weeksAgo) => SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->week->subWeeks($weeksAgo)]);
    $mark = fn (SectionPractice $practice, Trooper $trooper, ?AttendanceStatus $status) => Attendance::factory()->create([
        'trooper_id' => $trooper->id, 'section_practice_id' => $practice->id, 'status' => $status, 'source' => $status ? AttendanceSource::Manual : null,
    ]);

    // This week is in progress and never flagged.
    $mark($practice(0), $a, null);

    // 26Sep26: everyone marked (LOA counts), posted after the last mark.
    $done = $practice(1);
    $mark($done, $a, AttendanceStatus::Present);
    $mark($done, $b, AttendanceStatus::Loa);
    $done->forceFill(['rollcall_posted_at' => '2026-10-08 17:00:00'])->save();

    // 19Sep26: one signed but unmarked; marks of a departed or moved trooper do not count.
    $half = $practice(2);
    $mark($half, $a, AttendanceStatus::Missed);
    $mark($half, $b, null);
    $mark($half, $departed, AttendanceStatus::Present);
    $mark($half, $moved, AttendanceStatus::Present);

    // 12Sep26: everyone marked, but a mark changed after it was posted.
    $stale = $practice(3);
    $stale->forceFill(['rollcall_posted_at' => '2026-10-01 12:00:00'])->save();
    $mark($stale, $a, AttendanceStatus::Present);
    $mark($stale, $b, AttendanceStatus::Present);

    // 05Sep26 has no practice row at all; 29Aug26 is outside the window.
    $practice(5);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?week=2026-09-19")
        ->assertInertia(fn ($page) => $page->where('weekStatus', [
            ['date' => '2026-09-26', 'unmarked' => 0, 'posted' => true],
            ['date' => '2026-09-19', 'unmarked' => 1, 'posted' => false],
            ['date' => '2026-09-12', 'unmarked' => 0, 'posted' => false],
            ['date' => '2026-09-05', 'unmarked' => 2, 'posted' => false],
        ]));
});

it('sends no week status for an empty roster', function () {
    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page->where('weekStatus', []));
});

it('reads the week status in one query, however many weeks have practices', function () {
    $trooper = rollcallTrooper($this->section);
    foreach ([1, 2, 3, 4] as $weeksAgo) {
        $practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->week->subWeeks($weeksAgo)]);
        Attendance::factory()->create(['trooper_id' => $trooper->id, 'section_practice_id' => $practice->id]);
    }

    DB::enableQueryLog();
    $statuses = app(SectionPracticeRepository::class)->weekStatuses($this->section->id, $this->week->subWeeks(4), $this->week->subWeek());

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and($statuses->keys()->sort()->values()->all())->toBe(['2026-09-05', '2026-09-12', '2026-09-19', '2026-09-26'])
        ->and($statuses->every(fn ($status) => $status->marked === 1 && $status->posted === false))->toBeTrue();
});

it('counts a trooper whose leave covers a past week as having nothing to mark there, as the screen does', function () {
    $here = rollcallTrooper($this->section);
    $away = rollcallTrooper($this->section);
    $markedThenAway = rollcallTrooper($this->section);
    // Synced leave (4.4): $away is covered for 19Sep26 and 26Sep26, $markedThenAway for 19Sep26 only,
    // after a leader had already marked them present that week.
    Loa::factory()->create(['trooper_id' => $away->id, 'starts_at' => '2026-09-19', 'ends_at' => '2026-10-02']);
    Loa::factory()->create(['trooper_id' => $markedThenAway->id, 'starts_at' => '2026-09-19', 'ends_at' => '2026-09-25']);

    $posted = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->week->subWeek()]);
    Attendance::factory()->create(['trooper_id' => $here->id, 'section_practice_id' => $posted->id, 'status' => AttendanceStatus::Present, 'source' => AttendanceSource::Manual]);
    Attendance::factory()->create(['trooper_id' => $markedThenAway->id, 'section_practice_id' => $posted->id, 'status' => AttendanceStatus::Present, 'source' => AttendanceSource::Manual]);
    $posted->forceFill(['rollcall_posted_at' => '2026-10-08 17:00:00'])->save();

    $open = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->week->subWeeks(2)]);
    Attendance::factory()->create(['trooper_id' => $markedThenAway->id, 'section_practice_id' => $open->id, 'status' => AttendanceStatus::Present, 'source' => AttendanceSource::Manual]);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page
            ->where('weekStatus.0', ['date' => '2026-09-26', 'unmarked' => 0, 'posted' => true])
            ->where('weekStatus.1', ['date' => '2026-09-19', 'unmarked' => 1, 'posted' => false]));
});

// The card's attendance strip: the four completed Cav weeks before the week shown, oldest first.

function practiceWeek(Unit $section, CarbonImmutable $week, array $marks): SectionPractice
{
    $practice = SectionPractice::factory()->create(['section_id' => $section->id, 'week_start' => $week]);
    foreach ($marks as $trooperId => $status) {
        Attendance::factory()->create(['trooper_id' => $trooperId, 'section_practice_id' => $practice->id, 'status' => $status]);
    }

    return $practice;
}

it('sends each trooper\'s last four completed weeks of practice marks, oldest first', function () {
    $trooper = rollcallTrooper($this->section, ['username' => 'Adams.P', 'tis' => '2024-01-01']);
    $other = rollcallTrooper($this->section, ['username' => 'Baker.Q', 'tis' => '2024-01-01']);
    practiceWeek($this->section, $this->week->subWeeks(5), [$trooper->id => AttendanceStatus::Missed]);
    practiceWeek($this->section, $this->week->subWeeks(4), [$trooper->id => AttendanceStatus::Present, $other->id => AttendanceStatus::Attached]);
    // Three weeks back: no practice row at all.
    practiceWeek($this->section, $this->week->subWeeks(2), [$trooper->id => AttendanceStatus::Missed]);
    practiceWeek($this->section, $this->week->subWeek(), [$trooper->id => AttendanceStatus::Makeup]);
    // This week's mark is the card's own buttons, not history.
    practiceWeek($this->section, $this->week, [$trooper->id => AttendanceStatus::Missed]);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page
            ->where('troopers.0.id', $trooper->id)
            ->where('troopers.0.joined', '2024-01-01')
            ->where('troopers.0.history', [
                ['week' => '2026-09-05', 'status' => 'present', 'held' => true, 'loa' => false],
                ['week' => '2026-09-12', 'status' => null, 'held' => false, 'loa' => false],
                ['week' => '2026-09-19', 'status' => 'missed', 'held' => true, 'loa' => false],
                ['week' => '2026-09-26', 'status' => 'makeup', 'held' => true, 'loa' => false],
            ])
            // A held week the trooper was not marked in reads as held and unmarked, never as a miss.
            ->where('troopers.1.history', [
                ['week' => '2026-09-05', 'status' => 'attached', 'held' => true, 'loa' => false],
                ['week' => '2026-09-12', 'status' => null, 'held' => false, 'loa' => false],
                ['week' => '2026-09-19', 'status' => null, 'held' => true, 'loa' => false],
                ['week' => '2026-09-26', 'status' => null, 'held' => true, 'loa' => false],
            ]));
});

it('moves the strip\'s window with the week being viewed', function () {
    rollcallTrooper($this->section);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?week=2026-09-26")
        ->assertInertia(fn ($page) => $page->where('troopers.0.history', fn ($weeks) => collect($weeks)->pluck('week')->all() === [
            '2026-08-29', '2026-09-05', '2026-09-12', '2026-09-19',
        ]));
});

it('marks a week LOA when leave covers it, with or without a practice that week, or when it was marked loa', function () {
    $trooper = rollcallTrooper($this->section, ['username' => 'Adams.P']);
    $present = rollcallTrooper($this->section, ['username' => 'Baker.Q']);
    // Covers the Cav weeks of 12Sep26 (no practice row) and 19Sep26 (a held practice the trooper was not marked in).
    Loa::factory()->create(['trooper_id' => $trooper->id, 'starts_at' => '2026-09-14', 'ends_at' => '2026-09-21']);
    practiceWeek($this->section, $this->week->subWeeks(2), [$present->id => AttendanceStatus::Present]);
    // An older `loa` mark the resolver no longer backs still reads as LOA, as it does for the KPI service.
    practiceWeek($this->section, $this->week->subWeek(), [$trooper->id => AttendanceStatus::Loa]);
    // A leave that could not be read is not shown as an absence (AD-4).
    Loa::factory()->needsReview()->create(['trooper_id' => $trooper->id, 'posted_at' => '2026-09-06 12:00:00']);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page
            ->where('troopers.0.id', $trooper->id)
            ->where('troopers.0.history', fn ($weeks) => collect($weeks)->pluck('loa', 'week')->all() === [
                '2026-09-05' => false,
                '2026-09-12' => true,
                '2026-09-19' => true,
                '2026-09-26' => true,
            ])
            ->where('troopers.1.history', fn ($weeks) => collect($weeks)->every(fn ($week) => $week['loa'] === false)));
});

it('sends weeks with no practice as unheld and unmarked', function () {
    rollcallTrooper($this->section);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page->where('troopers.0.history', fn ($weeks) => count($weeks) === 4 && collect($weeks)
            ->every(fn ($week) => $week['status'] === null && $week['held'] === false && $week['loa'] === false)));
});

it('sends a recent recruit\'s join date with the full window, so the strip shows the weeks before it as not in the unit yet', function () {
    $recruit = rollcallTrooper($this->section, ['username' => 'Adams.P', 'tis' => '2026-09-21']);
    $veteran = rollcallTrooper($this->section, ['username' => 'Baker.Q', 'tis' => '2024-01-01']);
    practiceWeek($this->section, $this->week->subWeeks(3), [$veteran->id => AttendanceStatus::Present]);
    practiceWeek($this->section, $this->week->subWeek(), [$recruit->id => AttendanceStatus::Present]);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page
            ->where('troopers.0.id', $recruit->id)
            ->where('troopers.0.joined', '2026-09-21')
            ->where('troopers.0.history', fn ($weeks) => collect($weeks)->map(fn ($week) => [$week['status'], $week['held']])->all() === [
                [null, false],
                [null, true],
                [null, false],
                ['present', true],
            ]));
});

it('reads the strip in the same number of queries however many troopers the section has', function () {
    $queries = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $first = rollcallTrooper($this->section);
    foreach ([1, 2, 3, 4] as $weeksAgo) {
        practiceWeek($this->section, $this->week->subWeeks($weeksAgo), [$first->id => AttendanceStatus::Present]);
    }
    Loa::factory()->create(['trooper_id' => $first->id, 'starts_at' => '2026-09-14', 'ends_at' => '2026-09-21']);
    $baseline = $queries();

    foreach (range(1, 5) as $i) {
        $trooper = rollcallTrooper($this->section);
        Loa::factory()->create(['trooper_id' => $trooper->id, 'starts_at' => '2026-09-14', 'ends_at' => '2026-09-21']);
    }

    expect($queries())->toBe($baseline);
});

describe('the week a bare URL opens while last week\'s report is due', function () {
    beforeEach(function () {
        rollcallTrooper($this->section);
        // Saturday 10Oct26 20:00Z: the week of 03Oct26 is over, its report due at 23:59Z today.
        $this->travelTo(CarbonImmutable::parse('2026-10-10 20:00:00', 'UTC'));
    });

    it('opens the week whose report is still due, keeping the rest of the query', function () {
        $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?step=2")
            ->assertRedirect("/rollcall/{$this->section->id}?step=2&week=2026-10-03");

        $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?week=2026-10-03")
            ->assertInertia(fn ($page) => $page->where('week.date', '2026-10-03')->where('week.reporting', '2026-10-03')->where('week.isCurrent', false));
    });

    it('lets an explicit week win, the running one included', function () {
        $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?week=2026-10-10")
            ->assertOk()->assertInertia(fn ($page) => $page->where('week.date', '2026-10-10')->where('week.isCurrent', true));
    });

    it('opens the running week once the section has sent the report', function () {
        WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $this->week]);

        $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
            ->assertOk()->assertInertia(fn ($page) => $page->where('week.date', '2026-10-10'));
    });

    it('opens the running week once the deadline has passed', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-11 00:00:00', 'UTC'));

        $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
            ->assertOk()->assertInertia(fn ($page) => $page->where('week.date', '2026-10-10')->where('week.reporting', '2026-10-10'));
    });

    it('keeps a named week on the landing redirect, the running one included', function () {
        $this->actingAs($this->leader)->get('/rollcall?week=2026-10-10')
            ->assertRedirect("/rollcall/{$this->section->id}?week=2026-10-10");
        $this->actingAs($this->leader)->get('/rollcall')
            ->assertRedirect("/rollcall/{$this->section->id}");
    });
});

