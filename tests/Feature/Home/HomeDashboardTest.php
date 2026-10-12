<?php

use App\Kpi\KpiService;
use App\Kpi\Ratio;
use App\Kpi\ReportSnapshot;
use App\Kpi\UnitKpis;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\PortalRoleGrant;
use App\Models\RollcallSignature;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\SyncRunRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use Carbon\CarbonImmutable;

// Stories 5.9–5.11: the leader's Home dashboard. Scope from the ScopeResolver (AD-8), numbers from
// the shared KPI pieces (AD-21) and sent snapshots. "Now" is Thu 08Oct26, so the running cycle is
// the week opening Sat 03Oct26. Fixture tree:
//   1-7 ─ A/1-7 ─ 1/A ─┬─ 1/1/A  (4 troopers)
//                      └─ 2/1/A  (3 troopers)
//       └ B/1-7 ─ 1/B ─── 1/1/B  (2 troopers, outside Alpha's scope)

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-08 16:20:30');
    $units = app(UnitRepository::class);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', null);
    $this->alpha = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->bravo = $units->create(UnitType::Company, 'Bravo Company', 'B/1-7', $this->battalion->id);
    $this->p1 = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->alpha->id);
    $this->pb = $units->create(UnitType::Platoon, '1st Platoon', '1/B/1-7', $this->bravo->id);
    $this->s11 = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->p1->id);
    $this->s21 = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->p1->id);
    $this->sb = $units->create(UnitType::Section, '1st Section', '1/1/B/1-7', $this->pb->id);

    $this->week = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
    $this->s11Troopers = Trooper::factory()->count(4)->create(['primary_section_id' => $this->s11->id]);
    $this->s21Troopers = Trooper::factory()->count(3)->create(['primary_section_id' => $this->s21->id]);
    $this->sbTroopers = Trooper::factory()->count(2)->create(['primary_section_id' => $this->sb->id]);

    $this->commander = User::factory()->create();
    PortalRoleGrant::factory()->create(['user_id' => $this->commander->id, 'unit_id' => $this->alpha->id]);
    $this->sectionLeader = User::factory()->create();
    PortalRoleGrant::factory()->create(['user_id' => $this->sectionLeader->id, 'unit_id' => $this->s11->id]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** Marks `$trooper` in `$section` for `$week`; null `$status` leaves practice unmarked. */
function markWeek(Unit $section, Trooper $trooper, CarbonImmutable $week, ?AttendanceStatus $status, RollcallSignature $rollcall = RollcallSignature::WillAttend): void
{
    $practice = app(SectionPracticeRepository::class)->findOrCreate($section->id, $week);
    app(AttendanceRepository::class)->signRollcall($practice, $trooper->id, $rollcall);
    if ($status !== null) {
        app(AttendanceRepository::class)->markPractice($practice, $trooper->id, $status, AttendanceSource::Manual);
    }
}

function sentWeek(Unit $section, CarbonImmutable $week, UnitKpis $kpis): void
{
    $snapshot = (new ReportSnapshot($kpis, null))->toArray();
    app(WeeklyReportRepository::class)->send($section->id, $week, $snapshot, null, User::factory()->create()->id, 'digest', null);
}

function dashboard(User $user): Closure
{
    $response = test()->actingAs($user)->get('/home')->assertOk();

    return fn (Closure $assert) => $response->assertInertia($assert);
}

describe('KPI tiles (Story 5.9)', function () {
    it('shows the six KPIs live for the viewer\'s whole scope, from the shared KPI service', function () {
        markWeek($this->s11, $this->s11Troopers[0], $this->week, AttendanceStatus::Present);
        markWeek($this->s11, $this->s11Troopers[1], $this->week, AttendanceStatus::Missed, RollcallSignature::Unsigned);
        markWeek($this->s21, $this->s21Troopers[0], $this->week, AttendanceStatus::Makeup);
        markWeek($this->sb, $this->sbTroopers[0], $this->week, AttendanceStatus::Present);

        dashboard($this->commander)(fn ($page) => $page
            ->component('Home')
            ->where('kpis.current.strength', 7)
            ->where('kpis.current.makeups', 1)
            ->where('kpis.current.onLoa', 0)
            ->where('kpis.current.atRisk', 0));

        // The rates are compared with the service's own answer, not re-derived: this pins the
        // wiring, and the definition stays KpiService's (AD-21).
        $expected = app(KpiService::class)->forUnit($this->alpha, $this->week);
        dashboard($this->commander)(fn ($page) => $page
            ->where('kpis.current.rc', ['numerator' => $expected->rc30d->numerator, 'denominator' => $expected->rc30d->denominator])
            ->where('kpis.current.sp', ['numerator' => $expected->sp30d->numerator, 'denominator' => $expected->sp30d->denominator]));
    });

    it('shows a section leader only their own section, and never another company\'s troopers', function () {
        dashboard($this->sectionLeader)(fn ($page) => $page->where('kpis.current.strength', 4));
        dashboard($this->commander)(fn ($page) => $page->where('kpis.current.strength', 7));
    });

    it('gives a viewer with no scope no tiles rather than zeros', function () {
        dashboard(User::factory()->create())(fn ($page) => $page->where('kpis', null));
    });

    it('lets an admin see every unit', function () {
        dashboard(User::factory()->create(['is_admin' => true]))(fn ($page) => $page->where('kpis.current.strength', 9));
    });

    it('builds the sparkline history from the trailing four weeks of sent snapshots', function () {
        $snapshot = fn (int $strength, int $rc) => new UnitKpis($strength, new Ratio($rc, 4 * $strength), new Ratio(1, 2), 0, 0, 0);

        foreach ([4, 3] as $back) {
            sentWeek($this->s11, $this->week->subWeeks($back), $snapshot(4, 12));
            sentWeek($this->s21, $this->week->subWeeks($back), $snapshot(3, 6));
        }

        dashboard($this->commander)(fn ($page) => $page
            ->has('kpis.history', 4)
            ->where('kpis.history.0.week', '2026-09-05')
            ->where('kpis.history.0.kpis.strength', 7)
            ->where('kpis.history.0.kpis.rc', ['numerator' => 18, 'denominator' => 28])
            ->where('kpis.history.1.week', '2026-09-12')
            ->where('kpis.history.1.kpis.strength', 7)
            ->where('kpis.history.2.week', '2026-09-19')
            ->where('kpis.history.2.kpis', null)
            ->where('kpis.history.3.week', '2026-09-26')
            ->where('kpis.history.3.kpis', null));
    });

    it('leaves a week a gap when any section in scope did not send, and never counts a draft', function () {
        $kpis = new UnitKpis(4, new Ratio(1, 2), new Ratio(1, 2), 0, 0, 0);
        sentWeek($this->s11, $this->week->subWeek(), $kpis);
        app(WeeklyReportRepository::class)->saveDraft($this->s21->id, $this->week->subWeek(), [], null);

        dashboard($this->commander)(fn ($page) => $page->where('kpis.history.3.kpis', null));
        // The section leader's scope is just 1/1/A, which did send: a real point for them.
        dashboard($this->sectionLeader)(fn ($page) => $page->where('kpis.history.3.kpis.strength', 4));
    });

    it('does not build history from sections outside the viewer\'s scope', function () {
        $kpis = new UnitKpis(2, new Ratio(1, 2), new Ratio(1, 2), 0, 0, 0);
        sentWeek($this->sb, $this->week->subWeek(), $kpis);

        dashboard($this->sectionLeader)(fn ($page) => $page->where('kpis.history.3.kpis', null));
    });

    it('treats an unreadable snapshot as a gap instead of failing the page', function () {
        app(WeeklyReportRepository::class)->send($this->s11->id, $this->week->subWeek(), ['strength' => 'nope'], null, User::factory()->create()->id, 'd', null);

        dashboard($this->sectionLeader)(fn ($page) => $page->where('kpis.history.3.kpis', null));
    });

    it('reports the roster source and when it last synced, surfacing a failed or overdue sync', function () {
        $syncs = app(SyncRunRepository::class);

        dashboard($this->commander)(fn ($page) => $page->where('kpis.roster', ['source' => 'milpac', 'syncedAt' => null, 'state' => 'never']));

        $run = $syncs->open('roster', 'system');
        $syncs->complete($run, 1, 0, 0, 0);
        dashboard($this->commander)(fn ($page) => $page->where('kpis.roster.state', 'ok')->whereType('kpis.roster.syncedAt', 'string'));

        CarbonImmutable::setTestNow('2026-10-08 23:20:30');
        dashboard($this->commander)(fn ($page) => $page->where('kpis.roster.state', 'stale'));

        $syncs->fail($syncs->open('roster', 'system'), 'cav api down');
        dashboard($this->commander)(fn ($page) => $page->where('kpis.roster.state', 'failed'));
    });
});
