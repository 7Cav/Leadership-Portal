<?php

use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\Loa;
use App\Models\PortalRoleGrant;
use App\Models\RollcallSignature;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitActingLead;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Stories 2.8 / 4.6 / 4.7 / 4.9: the scope-gated roster. Fixture tree:
//   7CAV ─ 1-7 ─ A/1-7 ─┬─ 1/A ─┬─ 1/1/A (section, led by SGT Vasquez.R)
//        └ 2-7          │       └─ 2/1/A
//                       └─ 2/A ─── 1/2/A
// "Now" is Thursday 2026-10-08, so the current Cav week starts Saturday 2026-10-03.

const ROSTER_NOW = '2026-10-08 12:00:00';
const ROSTER_WEEK = '2026-10-03';

function rosterWeek(int $back = 0): CarbonImmutable
{
    return CarbonImmutable::parse(ROSTER_WEEK.' 00:00:00', 'UTC')->subWeeks($back);
}

function rosterTrooper(Unit $section, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

function rosterMark(Trooper $trooper, Unit $section, int $back, ?AttendanceStatus $status, ?RollcallSignature $rollcall = null, AttendanceSource $source = AttendanceSource::Manual): void
{
    $practice = app(SectionPracticeRepository::class)->findOrCreate($section->id, rosterWeek($back));
    if ($status !== null) {
        app(AttendanceRepository::class)->markPractice($practice, $trooper->id, $status, $source);
    }
    if ($rollcall !== null) {
        app(AttendanceRepository::class)->signRollcall($practice, $trooper->id, $rollcall);
    }
}

/** A user whose roster billet commands `$unit` (1.16's roster-derived scope). */
function rosterLeaderOf(Unit $unit, int $positionId, Unit $placedIn, array $attributes = []): array
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $positionId]);
    $trooper = rosterTrooper($placedIn, ['cav_position_id' => $positionId, ...$attributes]);

    return [User::factory()->serving()->create(['cav_id' => $trooper->cav_id]), $trooper];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(ROSTER_NOW, 'UTC'));
    $units = app(UnitRepository::class);

    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->battalion2 = $units->create(UnitType::Battalion, '2nd Battalion', '2-7', $this->regiment->id);
    $this->company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->platoon1 = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->company->id);
    $this->platoon2 = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->company->id);
    $this->section1 = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon1->id);
    $this->section2 = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->platoon1->id);
    $this->section3 = $units->create(UnitType::Section, '1st Section', '1/2/A/1-7', $this->platoon2->id);

    [$this->leader, $this->leaderTrooper] = rosterLeaderOf($this->section1, 501, $this->section1, [
        'rank_short' => 'SGT', 'billet' => 'Section Leader',
    ]);
});

it('requires authentication', function () {
    $this->get('/roster')->assertRedirect(route('login'));
});

it('opens a section leader on their own section with rank, name, billet and status (2.8)', function () {
    UnitCommandMapping::factory()->create(['unit_id' => $this->section1->id, 'cav_position_id' => 502]);
    $asl = rosterTrooper($this->section1, ['cav_position_id' => 502, 'rank_short' => 'CPL', 'rank' => 'Corporal', 'billet' => 'Assistant Section Leader', 'status' => TrooperStatus::Reserve]);
    rosterTrooper($this->section2);

    $this->actingAs($this->leader)->get('/roster')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Roster')
            ->where('unit.id', $this->section1->id)
            ->where('currentWeek', ROSTER_WEEK)
            ->has('weeks', 20)
            ->where('weeks.19', ROSTER_WEEK)
            ->has('troopers', 2)
            ->where('troopers.0.id', $this->leaderTrooper->id)
            ->where('troopers.0.name', $this->leaderTrooper->username)
            ->where('troopers.0.displayName', $this->leaderTrooper->display_name)
            ->where('troopers.0.role', 'SL')
            ->where('troopers.1.id', $asl->id)
            ->where('troopers.1.rank', 'CPL')
            ->where('troopers.1.rankFull', 'Corporal')
            ->where('troopers.1.billet', 'Assistant Section Leader')
            ->where('troopers.1.status', 'reserve')
            ->where('troopers.1.role', 'ASL')
            ->where('can.sync', false));
});

it('sends each trooper\'s join and promotion dates, for the order of battle\'s TIS and TIG columns', function () {
    $this->leaderTrooper->forceFill(['tis' => '2023-04-15', 'tig' => '2025-11-02'])->save();
    $unknown = rosterTrooper($this->section1, ['tis' => null, 'tig' => null]);

    $this->actingAs($this->leader)->get('/roster')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('troopers.0.id', $this->leaderTrooper->id)
            ->where('troopers.0.joined', '2023-04-15')
            ->where('troopers.0.tig', '2025-11-02')
            ->where('troopers.1.id', $unknown->id)
            ->where('troopers.1.joined', null)
            ->where('troopers.1.tig', null));
});

it('denies a unit outside the leader\'s scope server-side (2.8, 4.6, AD-8)', function () {
    $this->actingAs($this->leader)->get("/roster/{$this->section2->id}")->assertForbidden();
    $this->actingAs($this->leader)->get("/roster/{$this->company->id}")->assertForbidden();
});

it('404s a unit id too long for an integer rather than erroring', function () {
    $this->actingAs($this->leader)->get('/roster/99999999999999999999')->assertNotFound();
});

it('shows a granted unit\'s whole subtree, grouped with per-unit KPIs from the shared service (4.6)', function () {
    $staff = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $this->platoon1->id]);
    $a = rosterTrooper($this->section2);
    rosterMark($a, $this->section2, 0, AttendanceStatus::Missed, RollcallSignature::WillAttend);
    rosterMark($a, $this->section2, 1, AttendanceStatus::Missed, RollcallSignature::WillAttend);

    $this->actingAs($staff)->get("/roster/{$this->platoon1->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('unit.id', $this->platoon1->id)
            ->has('units', 3)
            ->where('units.0.id', $this->platoon1->id)
            ->where('units.0.leader', null)
            ->where('units.0.kpis.strength', 2)
            ->where('units.0.kpis.atRisk', 1)
            ->where('units.1.id', $this->section1->id)
            ->where('units.1.leader.id', $this->leaderTrooper->id)
            ->where('units.1.kpis.strength', 1)
            ->where('units.2.id', $this->section2->id)
            ->where('units.2.kpis.sp', [0, 2])
            ->where('units.2.kpis.rc', [2, 4])
            ->has('troopers', 2)
            ->has('scopeOptions', 3)
            ->where('scopeOptions.0.id', $this->platoon1->id)
            ->where('trail.0.inScope', false)
            ->has('trail', 4)
            ->where('trail.3.inScope', true));
});

it('flags at-risk from the shared helper, and never an LOA-covered or unmarked trooper (4.8, 4.9)', function () {
    $atRisk = rosterTrooper($this->section1);
    rosterMark($atRisk, $this->section1, 0, AttendanceStatus::Missed);
    rosterMark($atRisk, $this->section1, 2, AttendanceStatus::Missed);
    $onLoa = rosterTrooper($this->section1);
    rosterMark($onLoa, $this->section1, 0, AttendanceStatus::Loa);
    rosterMark($onLoa, $this->section1, 1, AttendanceStatus::Loa);
    rosterMark($onLoa, $this->section1, 2, AttendanceStatus::Missed);
    $unmarked = rosterTrooper($this->section1);

    $troopers = collect($this->actingAs($this->leader)->get('/roster')->viewData('page')['props']['troopers'])->keyBy('id');

    expect($troopers[$atRisk->id]['kpis']['atRisk'])->toBeTrue()
        ->and($troopers[$atRisk->id]['kpis']['missed'])->toBe(2)
        ->and($troopers[$onLoa->id]['kpis']['atRisk'])->toBeFalse()
        ->and($troopers[$onLoa->id]['kpis']['onLoa'])->toBeTrue()
        ->and($troopers[$unmarked->id]['kpis']['atRisk'])->toBeFalse();
});

it('lays out 20 Cav weeks of marks per trooper with each mark\'s source, null where no practice ran (4.7)', function () {
    $t = rosterTrooper($this->section1);
    rosterMark($t, $this->section1, 0, AttendanceStatus::Present, RollcallSignature::WillAttend, AttendanceSource::Auto);
    rosterMark($t, $this->section1, 19, AttendanceStatus::Excused, RollcallSignature::MakeupRequested);
    rosterMark($t, $this->section1, 20, AttendanceStatus::Missed); // before the window

    $row = collect($this->actingAs($this->leader)->get('/roster')->viewData('page')['props']['troopers'])->firstWhere('id', $t->id);

    expect($row['weeks'])->toHaveCount(20)
        ->and($row['weeks'][19])->toBe(['rollcall' => 'will_attend', 'status' => 'present', 'source' => 'auto', 'held' => true, 'sectionId' => $this->section1->id])
        ->and($row['weeks'][0])->toBe(['rollcall' => 'makeup_requested', 'status' => 'excused', 'source' => 'manual', 'held' => false, 'sectionId' => $this->section1->id])
        ->and($row['weeks'][10])->toBeNull();
});

it('shows a departed trooper as departed, unmeasured, rather than dropping them (2.8)', function () {
    $gone = rosterTrooper($this->section1, ['status' => TrooperStatus::Inactive]);

    $this->actingAs($this->leader)->get('/roster')->assertInertia(fn ($page) => $page
        ->has('troopers', 2)
        ->where('troopers.1.id', $gone->id)
        ->where('troopers.1.departed', true)
        ->where('troopers.1.kpis', null)
        ->where('units.0.kpis.strength', 1));
});

it('keeps a departed trooper\'s past marks in their history (2.8)', function () {
    $gone = rosterTrooper($this->section1);
    rosterMark($gone, $this->section1, 3, AttendanceStatus::Present, RollcallSignature::WillAttend);
    $gone->update(['status' => TrooperStatus::Inactive]);

    $row = collect($this->actingAs($this->leader)->get('/roster')->viewData('page')['props']['troopers'])->firstWhere('id', $gone->id);

    expect($row['departed'])->toBeTrue()
        ->and($row['weeks'][16]['status'])->toBe('present');
    $this->getJson("/roster/troopers/{$gone->id}")->assertJsonPath('trooper.weeks.16.status', 'present');
});

it('links the trooper Sheet to rollcall, milpac and the trooper page', function () {
    $this->actingAs($this->leader)->get('/roster')->assertInertia(fn ($page) => $page
        ->where('links.rollcall', '/rollcall/')
        ->where('links.milpac', 'https://7cav.us/rosters/profile?uniqueid=')
        ->where('links.trooper', '/troopers/'));
});

it('leaves an unplaced trooper off every section roster (4.6, AD-18)', function () {
    Trooper::factory()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get("/roster/{$this->regiment->id}")->assertInertia(fn ($page) => $page->has('troopers', 1));
});

it('shows the last successful sync, flagging a later failed run as stale (2.8, AD-20, AD-4)', function () {
    SyncRun::factory()->create(['source' => 'roster', 'finished_at' => '2026-10-08 09:14:00']);

    $this->actingAs($this->leader)->get('/roster')->assertInertia(fn ($page) => $page
        ->where('sync.source', 'milpac')
        ->where('sync.lastSuccessAt', '2026-10-08T09:14:00+00:00')
        ->where('sync.stale', false));

    SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Failure, 'finished_at' => '2026-10-08 10:14:00']);

    $this->actingAs($this->leader)->get('/roster')->assertInertia(fn ($page) => $page
        ->where('sync.lastSuccessAt', '2026-10-08T09:14:00+00:00')
        ->where('sync.stale', true)
        ->where('sync.lastAttempt.status', 'failure'));
});

it('flags a partial run as stale too, and clears the flag once a later run succeeds (2.8, AD-4)', function () {
    SyncRun::factory()->create(['source' => 'roster', 'finished_at' => '2026-10-08 08:14:00']);
    SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Partial, 'finished_at' => '2026-10-08 09:14:00']);

    $this->actingAs($this->leader)->get('/roster')->assertInertia(fn ($page) => $page
        ->where('sync.lastSuccessAt', '2026-10-08T08:14:00+00:00')
        ->where('sync.stale', true)
        ->where('sync.lastAttempt.status', 'partial'));

    SyncRun::factory()->create(['source' => 'roster', 'finished_at' => '2026-10-08 10:14:00']);

    $this->actingAs($this->leader)->get('/roster')->assertInertia(fn ($page) => $page
        ->where('sync.lastSuccessAt', '2026-10-08T10:14:00+00:00')
        ->where('sync.stale', false)
        ->where('sync.lastAttempt', null));
});

it('names the leave source and its last sync, stale once the latest LOA sync failed (4.3, AD-20)', function () {
    $this->actingAs($this->leader)->get('/roster')->assertInertia(fn ($page) => $page
        ->where('loaSync.source', 'Forum thread')
        ->where('loaSync.neverSynced', true));

    SyncRun::factory()->create(['source' => SyncRun::SOURCE_LOA, 'finished_at' => '2026-10-08 11:00:00']);
    SyncRun::factory()->create(['source' => SyncRun::SOURCE_LOA, 'status' => SyncRunStatus::Partial, 'finished_at' => '2026-10-08 11:30:00']);

    $this->actingAs($this->leader)->get('/roster')->assertInertia(fn ($page) => $page
        ->where('loaSync.lastSyncedAt', '2026-10-08T11:00:00+00:00')
        ->where('loaSync.stale', true)
        ->where('loaSync.latestStatus', 'partial'));
});

it('shows an Admin what the latest LOA sync could not read, and nobody else, nor an Admin viewing as a leader', function () {
    SyncRun::factory()->create(['source' => SyncRun::SOURCE_LOA, 'status' => SyncRunStatus::Partial, 'finished_at' => '2026-10-08 11:30:00', 'errored' => 1, 'needs_review' => 2, 'error' => 'Thread 812: The forum answered 503.']);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/roster')->assertInertia(fn ($page) => $page
        ->where('loaSyncProblems.unread', 1)
        ->where('loaSyncProblems.needsReview', 2)
        ->where('loaSyncProblems.problems', [['threadId' => 812, 'text' => 'Thread 812: The forum answered 503.']]));
    $this->actingAs($this->leader)->get('/roster')->assertInertia(fn ($page) => $page->where('loaSyncProblems', null));

    $this->actingAs($admin)->post('/view-as', ['kind' => 'user', 'id' => $this->leader->id]);
    $this->actingAs($admin)->get('/roster')->assertInertia(fn ($page) => $page->where('loaSyncProblems', null));
});

it('reports no sync yet rather than inventing a time', function () {
    $this->actingAs($this->leader)->get('/roster')->assertInertia(fn ($page) => $page
        ->where('sync.lastSuccessAt', null)
        ->where('sync.stale', false));
});

it('lands an Admin on the first battalion, not the whole regiment, and offers Sync now', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/roster')->assertInertia(fn ($page) => $page
        ->where('unit.id', $this->battalion->id)
        ->where('can.sync', true)
        ->where('scopeOptions.0.id', $this->regiment->id)
        ->where('scopeOptions.1.id', $this->battalion->id)
        ->where('scopeOptions.1.depth', 1));
});

it('narrows an Admin viewing as a section leader to that section, never offering Sync now (1.13, AD-9)', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section1->id])->assertSessionHasNoErrors();

    $this->get('/roster')->assertInertia(fn ($page) => $page
        ->where('unit.id', $this->section1->id)
        ->where('can.sync', false)
        ->has('scopeOptions', 1));
    $this->get("/roster/{$this->company->id}")->assertForbidden();
});

it('renders an empty roster for a user with no scope at all', function () {
    $nobody = User::factory()->serving()->create();

    $this->actingAs($nobody)->get('/roster')->assertOk()->assertInertia(fn ($page) => $page
        ->where('unit', null)
        ->has('troopers', 0)
        ->has('scopeOptions', 0));
});

it('reads a battalion in a constant number of queries, however many sections and troopers', function () {
    $admin = User::factory()->admin()->create();
    $count = function () use ($admin) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get("/roster/{$this->battalion->id}")->assertOk();

        return count(DB::getQueryLog());
    };

    foreach ([$this->section2, $this->section3] as $section) {
        $t = rosterTrooper($section);
        rosterMark($t, $section, 0, AttendanceStatus::Present, RollcallSignature::WillAttend);
    }
    $small = $count();

    $units = app(UnitRepository::class);
    foreach (range(1, 4) as $i) {
        $section = $units->create(UnitType::Section, "Extra {$i}", "{$i}/X", $this->platoon2->id);
        foreach (range(1, 5) as $j) {
            $t = rosterTrooper($section);
            rosterMark($t, $section, $j, AttendanceStatus::Missed, RollcallSignature::Unsigned);
        }
    }

    expect($count())->toBe($small);
});

describe('trooper detail (Sheet)', function () {
    // Decided 2026-10-27: after a transfer the new leadership sees the trooper's history from before the move.
    it('shows the new leadership the weeks from before a transfer, each with its section, and the move itself', function () {
        $t = rosterTrooper($this->section3);
        rosterMark($t, $this->section3, 2, AttendanceStatus::Present, RollcallSignature::WillAttend);
        $this->travelTo(rosterWeek(1)->addDays(4)->setTime(18, 0));
        app(TrooperRepository::class)->updatePrimarySections([$t->cav_id => $this->section1->id]);
        $this->travelTo(CarbonImmutable::parse(ROSTER_NOW, 'UTC'));
        rosterMark($t, $this->section1, 0, AttendanceStatus::Present, RollcallSignature::WillAttend);

        $this->actingAs($this->leader)->getJson("/roster/troopers/{$t->id}")
            ->assertOk()
            ->assertJsonPath('trooper.weeks.17.status', 'present')
            ->assertJsonPath('trooper.weeks.17.rollcall', 'will_attend')
            ->assertJsonPath('trooper.weeks.17.section', '1/2/A/1-7')
            ->assertJsonPath('trooper.weeks.19.section', '1/1/A/1-7')
            ->assertJsonPath('trooper.kpis.sp', [2, 2])
            ->assertJsonPath('trooper.kpis.rc', [2, 3])
            ->assertJsonPath('trooper.transfers', [['at' => '2026-09-30T18:00:00Z', 'from' => '1/2/A/1-7', 'to' => '1/1/A/1-7']]);
    });

    it('gives the first day of a leave starting next Cav week, for the "Leave from" chip, and null without one', function () {
        $leaving = rosterTrooper($this->section1);
        $staying = rosterTrooper($this->section1);
        Loa::factory()->create(['trooper_id' => $leaving->id, 'starts_at' => '2026-10-11', 'ends_at' => '2026-10-20']);

        $this->actingAs($this->leader)->getJson("/roster/troopers/{$leaving->id}")->assertOk()->assertJsonPath('trooper.leaveFrom', '2026-10-11');
        $this->actingAs($this->leader)->getJson("/roster/troopers/{$staying->id}")->assertOk()->assertJsonPath('trooper.leaveFrom', null);
    });

    it('lists no transfers for a trooper who never moved', function () {
        $t = rosterTrooper($this->section1);

        $this->actingAs($this->leader)->getJson("/roster/troopers/{$t->id}")->assertJsonPath('trooper.transfers', []);
    });

    it('returns identity, section, leader, KPIs, marks and the milpac link for an in-scope trooper', function () {
        $t = rosterTrooper($this->section1, ['cav_id' => 4242, 'mos' => '68W', 'tig' => '2026-01-02']);
        rosterMark($t, $this->section1, 0, AttendanceStatus::Makeup, RollcallSignature::CannotAttend);

        $this->actingAs($this->leader)->getJson("/roster/troopers/{$t->id}")
            ->assertOk()
            ->assertJsonPath('trooper.cavId', 4242)
            ->assertJsonPath('trooper.mos', '68W')
            ->assertJsonPath('trooper.tig', '2026-01-02')
            ->assertJsonPath('trooper.section.id', $this->section1->id)
            ->assertJsonPath('trooper.sectionLeader.id', $this->leaderTrooper->id)
            ->assertJsonPath('trooper.milpacUrl', 'https://7cav.us/rosters/profile?uniqueid=4242')
            ->assertJsonPath('trooper.weeks.19.status', 'makeup')
            ->assertJsonPath('trooper.kpis.sp', [1, 1])
            ->assertJsonPath('currentWeek', ROSTER_WEEK);
    });

    it('names the weeks the trooper missed, for the Sheet\'s makeup PM, and none for a departed trooper', function () {
        $t = rosterTrooper($this->section1);
        rosterMark($t, $this->section1, 0, AttendanceStatus::Missed);
        rosterMark($t, $this->section1, 2, AttendanceStatus::Missed);
        rosterMark($t, $this->section1, 1, AttendanceStatus::Present);
        $gone = rosterTrooper($this->section1, ['status' => TrooperStatus::Inactive]);

        $this->actingAs($this->leader)->getJson("/roster/troopers/{$t->id}")
            ->assertJsonPath('trooper.missedWeeks', [rosterWeek(2)->toDateString(), ROSTER_WEEK]);
        $this->getJson("/roster/troopers/{$gone->id}")->assertJsonPath('trooper.missedWeeks', []);
    });

    it('opens a trooper anywhere in a granted subtree', function () {
        $staff = User::factory()->serving()->create();
        PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $this->platoon1->id]);
        $t = rosterTrooper($this->section2);

        $this->actingAs($staff)->getJson("/roster/troopers/{$t->id}")->assertOk()->assertJsonPath('trooper.id', $t->id);
    });

    it('refuses a trooper outside a view-as preview\'s narrowed scope (1.13, AD-9)', function () {
        $admin = User::factory()->admin()->create();
        $outside = rosterTrooper($this->section2);
        $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section1->id]);

        $this->getJson("/roster/troopers/{$outside->id}")->assertNotFound();
    });

    it('shows a departed trooper as departed and unmeasured', function () {
        $gone = rosterTrooper($this->section1, ['status' => TrooperStatus::Inactive]);

        $this->actingAs($this->leader)->getJson("/roster/troopers/{$gone->id}")
            ->assertOk()
            ->assertJsonPath('trooper.departed', true)
            ->assertJsonPath('trooper.kpis', null);
    });

    it('requires authentication', function () {
        $this->get('/roster/troopers/1')->assertRedirect(route('login'));
    });

    it('answers 404 for a trooper outside scope, unknown or unplaced alike, so ids cannot be enumerated', function () {
        $outside = rosterTrooper($this->section2);
        $unplaced = Trooper::factory()->create();

        $this->actingAs($this->leader)->getJson("/roster/troopers/{$outside->id}")->assertNotFound();
        $this->actingAs($this->leader)->getJson("/roster/troopers/{$unplaced->id}")->assertNotFound();
        $this->actingAs($this->leader)->getJson('/roster/troopers/999999')->assertNotFound();
    });

    it('404s a trooper id too long for an integer rather than erroring', function () {
        $this->actingAs($this->leader)->getJson('/roster/troopers/99999999999999999999')->assertNotFound();
    });
});

// Acting leads (2026-10-25): the roster opens on the unit a leader leads, by billet or as acting lead.

it('opens an acting lead with no command billet on the unit they act as lead of, not the top of a wider scope', function () {
    $lead = rosterTrooper($this->section3, ['cav_position_id' => 999]);
    $user = User::factory()->serving()->create(['cav_id' => $lead->cav_id]);
    // A staff grant on the battalion makes the top of their scope the battalion.
    PortalRoleGrant::factory()->create(['user_id' => $user->id, 'unit_id' => $this->battalion->id]);
    UnitActingLead::query()->create(['unit_id' => $this->platoon2->id, 'trooper_id' => $lead->id, 'assigned_by' => null]);

    $this->actingAs($user)->get('/roster')->assertInertia(fn ($page) => $page->where('unit.id', $this->platoon2->id));
});

it('opens an acting lead of several units on the first of them in designation order', function () {
    $lead = rosterTrooper($this->section3, ['cav_position_id' => 999]);
    $user = User::factory()->serving()->create(['cav_id' => $lead->cav_id]);
    PortalRoleGrant::factory()->create(['user_id' => $user->id, 'unit_id' => $this->battalion->id]);
    // Assigned out of order: the 2nd platoon first, then the 1st.
    UnitActingLead::query()->create(['unit_id' => $this->platoon2->id, 'trooper_id' => $lead->id, 'assigned_by' => null]);
    UnitActingLead::query()->create(['unit_id' => $this->platoon1->id, 'trooper_id' => $lead->id, 'assigned_by' => null]);

    $this->actingAs($user)->get('/roster')->assertInertia(fn ($page) => $page->where('unit.id', $this->platoon1->id));
});

it('keeps a leader by billet on their billet\'s unit when they are also acting lead elsewhere', function () {
    UnitActingLead::query()->create(['unit_id' => $this->platoon2->id, 'trooper_id' => $this->leaderTrooper->id, 'assigned_by' => null]);

    $this->actingAs($this->leader)->get('/roster')->assertInertia(fn ($page) => $page->where('unit.id', $this->section1->id));
});
