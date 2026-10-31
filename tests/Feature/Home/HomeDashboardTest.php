<?php

use App\Kpi\Headcount;
use App\Kpi\KpiService;
use App\Kpi\Ratio;
use App\Kpi\ReportKpisUnavailableException;
use App\Kpi\ReportSnapshot;
use App\Kpi\UnitKpis;
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
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

// Stories 5.9–5.11: the leader's Home dashboard. Scope from the ScopeResolver (AD-8), numbers from
// the shared KPI pieces (AD-21) and sent snapshots. "Now" is Thu 08OCT26, so the running cycle is
// the week opening Sat 03OCT26. Fixture tree:
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

    $this->commander = User::factory()->serving()->create();
    PortalRoleGrant::factory()->create(['user_id' => $this->commander->id, 'unit_id' => $this->alpha->id]);
    $this->sectionLeader = User::factory()->serving()->create();
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
    app(WeeklyReportRepository::class)->send($section->id, $week, $snapshot, null, User::factory()->serving()->create()->id, 'digest', null);
}

/** @return array<string, mixed> the page's shape of one set of KPIs */
function homeKpiShape(UnitKpis $kpis): array
{
    return [
        'strength' => $kpis->strength,
        'rc' => ['numerator' => $kpis->rc30d->numerator, 'denominator' => $kpis->rc30d->denominator],
        'sp' => ['numerator' => $kpis->sp30d->numerator, 'denominator' => $kpis->sp30d->denominator],
        'atRisk' => $kpis->atRisk,
        'onLoa' => $kpis->onLoa,
        'makeups' => $kpis->makeups,
    ];
}

function dashboard(User $user): Closure
{
    $response = test()->actingAs($user)->get('/home')->assertOk();

    return fn (Closure $assert) => $response->assertInertia($assert);
}

describe('KPI tiles (Story 5.9)', function () {
    it('shows the headcount beside the measured strength: staff and groups in, New Recruits only on their own group (#424)', function () {
        // HQ staff on the platoon and on the company, a staff department under the company, and a recruit in its own group.
        $staff = app(UnitRepository::class)->create(UnitType::Group, 'S1 Staff', 'S1', $this->alpha->id, UnitKind::Staff);
        $recruits = app(UnitRepository::class)->create(UnitType::Group, 'New Recruits', 'NR', $this->alpha->id, UnitKind::Recruit);
        foreach ([$this->p1, $this->alpha, $staff, $recruits] as $unit) {
            Trooper::factory()->create(['primary_section_id' => $unit->id]);
        }

        dashboard($this->commander)(fn ($page) => $page
            ->where('kpis.current.strength', 7)
            ->where('kpis.current.headcount', 10)
            ->etc());
    });

    it('keeps the history to the measured figures, with no headcount', function () {
        dashboard($this->commander)(fn ($page) => $page->missing('kpis.history.0.kpis.headcount')->etc());
    });

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
        dashboard(User::factory()->serving()->create())(fn ($page) => $page->where('kpis', null));
    });

    it('lets an admin see every unit', function () {
        dashboard(User::factory()->serving()->create(['is_admin' => true]))(fn ($page) => $page->where('kpis.current.strength', 9));
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
        app(WeeklyReportRepository::class)->send($this->s11->id, $this->week->subWeeks(2), ['strength' => 'nope'], null, User::factory()->serving()->create()->id, 'd', null);

        dashboard($this->sectionLeader)(fn ($page) => $page->where('kpis.history.2.kpis', null));
    });

    it('renders when a sent snapshot this week is unreadable, showing that unit as unavailable', function () {
        Exceptions::fake();
        app(WeeklyReportRepository::class)->send($this->p1->id, $this->week, ['corrupt' => true], null, User::factory()->serving()->create()->id, 'd', null);
        app(WeeklyReportRepository::class)->send($this->s11->id, $this->week, ['corrupt' => true], null, User::factory()->serving()->create()->id, 'd', null);

        // The company commander's table lists the platoon, whose own sent snapshot is unreadable.
        dashboard($this->commander)(fn ($page) => $page
            ->where('units.0.id', $this->p1->id)
            ->where('units.0.status', 'sent')
            ->where('units.0.kpis', null)
            ->where('kpis.current.strength', 7));
        // The section leader's own section: sent, unreadable, the page still renders.
        dashboard($this->sectionLeader)(fn ($page) => $page->where('units.0.id', $this->s11->id)->where('units.0.kpis', null));

        Exceptions::assertReported(ReportKpisUnavailableException::class);
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

    it('names the leave source and its last sync beside the tiles, stale once the latest LOA sync failed (4.3, AD-20)', function () {
        dashboard($this->commander)(fn ($page) => $page
            ->where('kpis.loaSync.source', 'Forum thread')
            ->where('kpis.loaSync.neverSynced', true)
            ->where('kpis.loaSync.lastSyncedAt', null));

        SyncRun::factory()->create(['source' => SyncRun::SOURCE_LOA, 'finished_at' => '2026-10-08 15:00:00']);
        dashboard($this->commander)(fn ($page) => $page
            ->where('kpis.loaSync.lastSyncedAt', '2026-10-08T15:00:00+00:00')
            ->where('kpis.loaSync.stale', false));

        SyncRun::factory()->create(['source' => SyncRun::SOURCE_LOA, 'status' => SyncRunStatus::Failure, 'finished_at' => '2026-10-08 16:00:00']);
        dashboard($this->commander)(fn ($page) => $page
            ->where('kpis.loaSync.lastSyncedAt', '2026-10-08T15:00:00+00:00')
            ->where('kpis.loaSync.stale', true)
            ->where('kpis.loaSync.latestStatus', 'failure'));
    });
});

describe('Needs attention (Story 5.10)', function () {
    $sendAll = function (array $sections, $test) {
        $kpis = new UnitKpis(3, new Ratio(1, 2), new Ratio(1, 2), 0, 0, 0);
        foreach ($sections as $section) {
            sentWeek($section, $test->week, $kpis);
        }
    };

    it('lists the draft and unstarted section reports holding the chain, drafts first, each linking to its report', function () {
        app(WeeklyReportRepository::class)->saveDraft($this->s21->id, $this->week, [], null);

        dashboard($this->commander)(fn ($page) => $page
            ->where('attention.totals', ['holding' => 2, 'atRisk' => 0, 'loaReview' => 0, 'makeupOwed' => 0])
            ->where('attention.items.0.kind', 'holding')
            ->where('attention.items.0.unit.designation', '2/1/A/1-7')
            ->where('attention.items.0.status', 'draft')
            ->where('attention.items.0.holds.designation', '1/A/1-7')
            ->where('attention.items.0.href', route('reports.show', ['unit' => $this->s21->id, 'week' => '2026-10-03']))
            ->where('attention.items.1.unit.designation', '1/1/A/1-7')
            ->where('attention.items.1.status', 'not_started')
            ->where('attention.items.1.href', route('reports.show', ['unit' => $this->s11->id, 'week' => '2026-10-03'])));
    });

    it('blames the platoon only once its own sections are all in', function () use ($sendAll) {
        $sendAll([$this->s11, $this->s21], $this);

        dashboard($this->commander)(fn ($page) => $page
            ->where('attention.totals.holding', 1)
            ->where('attention.items.0.unit.designation', '1/A/1-7')
            ->where('attention.items.0.status', 'not_started')
            ->where('attention.items.0.holds.designation', 'A/1-7')
            ->where('attention.items.0.href', route('reports.show', ['unit' => $this->p1->id, 'week' => '2026-10-03'])));
    });

    it('lists at-risk troopers by the shared definition, with their section and a roster link', function () {
        [$risky, $fine] = [$this->s11Troopers[0], $this->s11Troopers[1]];
        foreach ([0, 1] as $back) {
            markWeek($this->s11, $risky, $this->week->subWeeks($back), AttendanceStatus::Missed);
        }
        markWeek($this->s11, $fine, $this->week, AttendanceStatus::Missed);

        dashboard($this->commander)(fn ($page) => $page
            ->where('attention.totals.atRisk', 1)
            ->where('attention.items.2.kind', 'at_risk')
            ->where('attention.items.2.trooper.id', $risky->id)
            ->where('attention.items.2.trooper.displayName', $risky->display_name)
            ->where('attention.items.2.section.designation', '1/1/A/1-7')
            ->where('attention.items.2.missed', 2)
            ->where('attention.items.2.missedWeeks', [$this->week->subWeek()->toDateString(), $this->week->toDateString()])
            ->where('attention.items.2.windowWeeks', 4)
            ->where('attention.items.2.href', route('roster.index', ['unit' => $this->s11->id, 'trooper' => $risky->id])));
    });

    it('only shows what is in the viewer\'s scope', function () {
        foreach ([0, 1] as $back) {
            markWeek($this->sb, $this->sbTroopers[0], $this->week->subWeeks($back), AttendanceStatus::Missed);
        }
        app(WeeklyReportRepository::class)->saveDraft($this->sb->id, $this->week, [], null);

        // Bravo's draft and at-risk trooper are invisible to Alpha's commander and to a section
        // leader; only 1/1/A's own (unstarted) report is the leader's, and it holds nothing up.
        dashboard($this->commander)(fn ($page) => $page->where('attention.totals.atRisk', 0)->where('attention.totals.holding', 2)->where('attention.items.0.unit.designation', '1/1/A/1-7'));
        dashboard($this->sectionLeader)(fn ($page) => $page->where('attention.totals', ['holding' => 0, 'atRisk' => 0, 'loaReview' => 0, 'makeupOwed' => 0]));
        dashboard(User::factory()->serving()->create(['is_admin' => true]))(fn ($page) => $page->where('attention.totals.atRisk', 1));
    });

    it('is an explicit empty list, not missing, when nothing needs attention', function () use ($sendAll) {
        $sendAll([$this->s11, $this->s21], $this);
        $kpis = new UnitKpis(7, new Ratio(1, 2), new Ratio(1, 2), 0, 0, 0);
        app(WeeklyReportRepository::class)->send($this->p1->id, $this->week, (new ReportSnapshot($kpis, null))->toArray(), null, User::factory()->serving()->create()->id, 'd', null);

        dashboard($this->commander)(fn ($page) => $page->where('attention.items', [])->where('attention.totals', ['holding' => 0, 'atRisk' => 0, 'loaReview' => 0, 'makeupOwed' => 0]));
    });

    it('has no worklist for a viewer with no scope', function () {
        dashboard(User::factory()->serving()->create())(fn ($page) => $page->where('attention', null));
    });

    it('caps each group but counts them all', function () {
        $section = $this->s11;
        Trooper::factory()->count(12)->create(['primary_section_id' => $section->id])->each(function ($trooper) use ($section) {
            foreach ([0, 1] as $back) {
                markWeek($section, $trooper, $this->week->subWeeks($back), AttendanceStatus::Missed);
            }
        });

        dashboard($this->commander)(fn ($page) => $page
            ->where('attention.totals.atRisk', 12)
            ->has('attention.items', 2 + 10));
    });

    describe('LOA posts that need review', function () {
        it('lists a trooper in scope whose leave post needs review, linking to their card on the rollcall and to the post', function () {
            $trooper = $this->s21Troopers[0];
            $loa = Loa::factory()->needsReview()->create(['trooper_id' => $trooper->id, 'posted_at' => '2026-10-04 12:00:00', 'review_reason' => 'The End Date could not be read.']);
            // A cleanly read leave is not listed, and a broken post outside the scope is not either.
            Loa::factory()->create(['trooper_id' => $this->s11Troopers[0]->id, 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-20']);
            Loa::factory()->needsReview()->create(['trooper_id' => $this->sbTroopers[0]->id, 'posted_at' => '2026-10-04 12:00:00']);

            dashboard($this->commander)(fn ($page) => $page
                ->where('attention.totals.loaReview', 1)
                ->where('attention.items.2.kind', 'loa_review')
                ->where('attention.items.2.trooper.id', $trooper->id)
                ->where('attention.items.2.section.designation', '2/1/A/1-7')
                ->where('attention.items.2.typeLabel', 'LOA')
                ->where('attention.items.2.reviewReason', 'The End Date could not be read.')
                ->where('attention.items.2.sourceThread', $loa->source_thread)
                ->where('attention.items.2.href', route('rollcall.show', ['unit' => $this->s21->id, 'week' => '2026-10-03', 'focus' => $trooper->id])));
        });

        it('shows a section leader only their own section\'s posts, and an Admin every one', function () {
            Loa::factory()->needsReview()->create(['trooper_id' => $this->s21Troopers[0]->id, 'posted_at' => '2026-10-04 12:00:00']);
            Loa::factory()->needsReview()->create(['trooper_id' => $this->sbTroopers[0]->id, 'posted_at' => '2026-10-04 12:00:00']);

            dashboard($this->sectionLeader)(fn ($page) => $page->where('attention.totals.loaReview', 0));
            dashboard(User::factory()->serving()->create(['is_admin' => true]))(fn ($page) => $page->where('attention.totals.loaReview', 2));
        });

        it('stops listing a post once the LOA definition no longer applies it to the week', function () {
            // Posted two weeks before the running one: the coverage rule lets it shield that week and the next only.
            Loa::factory()->needsReview()->create(['trooper_id' => $this->s21Troopers[0]->id, 'posted_at' => '2026-09-20 12:00:00']);

            dashboard($this->commander)(fn ($page) => $page->where('attention.totals.loaReview', 0));
        });

        it('lists a post made since the closing week ended while that week\'s report is still due', function () {
            CarbonImmutable::setTestNow('2026-10-10 12:00:00');
            Loa::factory()->needsReview()->create(['trooper_id' => $this->s21Troopers[1]->id, 'posted_at' => '2026-10-10 09:00:00']);

            dashboard($this->commander)(fn ($page) => $page
                ->where('attention.totals.loaReview', 1)
                ->where('attention.items.2.trooper.id', $this->s21Troopers[1]->id)
                ->where('attention.items.2.href', route('rollcall.show', ['unit' => $this->s21->id, 'week' => '2026-10-03', 'focus' => $this->s21Troopers[1]->id])));
        });
    });
});

describe('Subordinate units (Story 5.11)', function () {
    it('lists each direct subordinate of the viewer\'s scope with its KPIs and send status', function () {
        $kpis = new UnitKpis(4, new Ratio(3, 4), new Ratio(2, 4), 1, 0, 1);
        sentWeek($this->s11, $this->week, $kpis);
        app(WeeklyReportRepository::class)->saveDraft($this->s21->id, $this->week, [], null);

        // Alpha's direct subordinate is 1st Platoon, which is holding because 2/1/A is a draft.
        dashboard($this->commander)(fn ($page) => $page
            ->has('units', 1)
            ->where('units.0.id', $this->p1->id)
            ->where('units.0.designation', '1/A/1-7')
            ->where('units.0.type', 'platoon')
            ->where('units.0.status', 'not_started')
            ->where('units.0.holding', true)
            ->where('units.0.coverage', ['sent' => 1, 'of' => 2])
            ->where('units.0.live', true)
            ->where('units.0.href', route('reports.show', ['unit' => $this->p1->id, 'week' => '2026-10-03'])));
    });

    it('shows a non-leaf unit live for its whole subtree, not the partial sum of the sections that sent', function () {
        sentWeek($this->s11, $this->week, new UnitKpis(4, new Ratio(3, 4), new Ratio(2, 4), 1, 0, 1));

        $live = app(KpiService::class)->forUnit($this->p1, $this->week);
        dashboard($this->commander)(fn ($page) => $page
            ->where('units.0.kpis.strength', $live->strength)
            ->where('units.0.kpis.strength', 7));
    });

    it('reads every live non-leaf row without a query per unit, each equal to that unit\'s own KPIs', function () {
        $admin = User::factory()->serving()->create(['is_admin' => true]);
        markWeek($this->s11, $this->s11Troopers[0], $this->week, AttendanceStatus::Present);
        markWeek($this->sb, $this->sbTroopers[0], $this->week, AttendanceStatus::Missed);
        $queries = function () use ($admin) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            test()->actingAs($admin)->get('/home')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $before = $queries();
        $units = app(UnitRepository::class);
        $charlie = $units->create(UnitType::Company, 'Charlie Company', 'C/1-7', $this->battalion->id);
        $pc = $units->create(UnitType::Platoon, '1st Platoon', '1/C/1-7', $charlie->id);
        $sc = $units->create(UnitType::Section, '1st Section', '1/1/C/1-7', $pc->id);
        markWeek($sc, Trooper::factory()->create(['primary_section_id' => $sc->id]), $this->week, AttendanceStatus::Makeup);

        expect($queries())->toBe($before);
        $kpis = app(KpiService::class);
        dashboard($admin)(fn ($page) => $page
            ->has('units', 3)
            ->where('units.0.kpis', homeKpiShape($kpis->forUnit($this->alpha, $this->week)))
            ->where('units.1.kpis', homeKpiShape($kpis->forUnit($this->bravo, $this->week)))
            ->where('units.2.kpis', homeKpiShape($kpis->forUnit($charlie, $this->week)))
            ->where('kpis.current', [...homeKpiShape($kpis->forUnit($this->battalion, $this->week)), 'headcount' => app(Headcount::class)->forUnit($this->battalion)]));
    });

    it('shows a sent unit as its frozen snapshot, and a leaf-only viewer their own section', function () {
        $frozen = new UnitKpis(9, new Ratio(5, 9), new Ratio(1, 2), 2, 1, 3);
        sentWeek($this->s11, $this->week, $frozen);

        dashboard($this->sectionLeader)(fn ($page) => $page
            ->has('units', 1)
            ->where('units.0.id', $this->s11->id)
            ->where('units.0.status', 'sent')
            ->where('units.0.live', false)
            ->where('units.0.holding', false)
            ->where('units.0.coverage', null)
            ->where('units.0.kpis', ['strength' => 9, 'rc' => ['numerator' => 5, 'denominator' => 9], 'sp' => ['numerator' => 1, 'denominator' => 2], 'atRisk' => 2, 'onLoa' => 1, 'makeups' => 3]));
    });

    it('agrees with the report roll-up on status and holding', function () {
        sentWeek($this->s11, $this->week, new UnitKpis(4, new Ratio(1, 2), new Ratio(1, 2), 0, 0, 0));

        $rollup = test()->actingAs($this->commander)->get('/reports')->viewData('page')['props']['roots'][0]['children'][0];
        dashboard($this->commander)(fn ($page) => $page
            ->where('units.0.status', $rollup['status'])
            ->where('units.0.holding', $rollup['holding'])
            ->where('units.0.coverage', ['sent' => $rollup['coverage']['sent'], 'of' => $rollup['coverage']['of']]));
    });

    it('lists the children of every root when the scope has several, and never another company\'s units', function () {
        $multi = User::factory()->serving()->create();
        PortalRoleGrant::factory()->create(['user_id' => $multi->id, 'unit_id' => $this->p1->id]);
        PortalRoleGrant::factory()->create(['user_id' => $multi->id, 'unit_id' => $this->sb->id]);

        dashboard($multi)(fn ($page) => $page
            ->has('units', 3)
            ->where('units.0.id', $this->s11->id)
            ->where('units.1.id', $this->s21->id)
            ->where('units.2.id', $this->sb->id));
        dashboard($this->commander)(fn ($page) => $page->where('units.0.id', $this->p1->id)->has('units', 1));
    });

    it('has no rows for a viewer with no scope', function () {
        dashboard(User::factory()->serving()->create())(fn ($page) => $page->where('units', []));
    });
});

describe('Week step matrix', function () {
    it('lists every section in scope with its five steps and the screens that do them', function () {
        $practice = app(SectionPracticeRepository::class)->findOrCreate($this->s11->id, $this->week);
        app(SectionPracticeRepository::class)->markRollcallPosted($practice, $this->sectionLeader->id);
        markWeek($this->s11, $this->s11Troopers[0], $this->week, AttendanceStatus::Present);
        markWeek($this->s11, $this->s11Troopers[1], $this->week, null, RollcallSignature::Unsigned);
        markWeek($this->s11, $this->s11Troopers[2], $this->week, AttendanceStatus::Loa);
        app(WeeklyReportRepository::class)->saveDraft($this->s21->id, $this->week, [], null);

        dashboard($this->commander)(fn ($page) => $page
            ->has('steps', 2)
            ->where('steps.0.id', $this->s11->id)
            ->where('steps.0.designation', '1/1/A/1-7')
            ->where('steps.0.platoon', '1/A/1-7')
            ->where('steps.0.strength', 4)
            ->whereType('steps.0.postedAt', 'string')
            ->where('steps.0.signed', 2)
            ->where('steps.0.marked', 2)
            ->where('steps.0.held', true)
            ->where('steps.0.report', 'not_started')
            ->where('steps.0.links', [
                'posted' => route('rollcall.show', ['unit' => $this->s11->id, 'week' => '2026-10-03', 'step' => 3]),
                'signed' => route('rollcall.show', ['unit' => $this->s11->id, 'week' => '2026-10-03', 'step' => 1]),
                'held' => route('rollcall.show', ['unit' => $this->s11->id, 'week' => '2026-10-03', 'step' => 2]),
                'marked' => route('rollcall.show', ['unit' => $this->s11->id, 'week' => '2026-10-03', 'step' => 2]),
                'report' => route('reports.show', ['unit' => $this->s11->id, 'week' => '2026-10-03']),
            ])
            ->where('steps.0.links.posted', fn (string $url) => str_ends_with($url, "/rollcall/{$this->s11->id}?week=2026-10-03&step=3"))
            ->where('steps.1.id', $this->s21->id)
            ->where('steps.1.postedAt', null)
            ->where('steps.1.signed', 0)
            ->where('steps.1.held', false)
            ->where('steps.1.report', 'draft'));
    });

    it('counts a practice with only LOA marks as not held, and only this week\'s marks', function () {
        markWeek($this->s11, $this->s11Troopers[0], $this->week, AttendanceStatus::Loa);
        markWeek($this->s11, $this->s11Troopers[1], $this->week->subWeek(), AttendanceStatus::Present);

        dashboard($this->sectionLeader)(fn ($page) => $page
            ->where('steps.0.held', false)
            ->where('steps.0.signed', 1)
            ->where('steps.0.marked', 1));
    });

    it('counts a trooper whose leave covers the week as signed and marked, once, so both steps can reach strength (3.4)', function () {
        // Two on leave: one with no row at all, one who also signed. Nothing stores an `loa` mark any more.
        Loa::factory()->create(['trooper_id' => $this->s11Troopers[0]->id, 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-20']);
        Loa::factory()->create(['trooper_id' => $this->s11Troopers[1]->id, 'starts_at' => '2026-10-05', 'ends_at' => '2026-10-06']);
        markWeek($this->s11, $this->s11Troopers[1], $this->week, null);
        markWeek($this->s11, $this->s11Troopers[2], $this->week, AttendanceStatus::Present);
        markWeek($this->s11, $this->s11Troopers[3], $this->week, AttendanceStatus::Missed);
        // A leave that needs review asserts nothing: that trooper still has a mark to make (AD-4).
        Loa::factory()->needsReview()->create(['trooper_id' => $this->s21Troopers[0]->id, 'posted_at' => '2026-10-04 12:00:00']);

        dashboard($this->commander)(fn ($page) => $page
            ->where('steps.0.strength', 4)
            ->where('steps.0.signed', 4)
            ->where('steps.0.marked', 4)
            ->where('steps.0.held', true)
            ->where('steps.1.marked', 0));
    });

    it('marks a sent report and leaves out sections beyond the viewer\'s scope', function () {
        sentWeek($this->s11, $this->week, new UnitKpis(4, new Ratio(1, 2), new Ratio(1, 2), 0, 0, 0));

        dashboard($this->sectionLeader)(fn ($page) => $page
            ->has('steps', 1)
            ->where('steps.0.report', 'sent')
            ->whereType('steps.0.sentAt', 'string'));
        dashboard($this->commander)(fn ($page) => $page->has('steps', 2));
        dashboard(User::factory()->serving()->create())(fn ($page) => $page->where('steps', []));
    });

    it('does not count departed troopers', function () {
        $this->s11Troopers[3]->forceFill(['status' => TrooperStatus::Inactive])->save();

        dashboard($this->sectionLeader)(fn ($page) => $page->where('steps.0.strength', 3));
    });
});

describe('LOA posts that need review after a move (the 2026-10-29 audit)', function () {
    // Saturday 10OCT26, with the 03OCT26 reports due at 23:59: the sync moved one of 1/1/A's troopers to Bravo and
    // one of Bravo's to 1/1/A at 08:00. Each week is checked against its own roster in the viewer's scope.
    beforeEach(function () {
        CarbonImmutable::setTestNow('2026-10-10 08:00:00');
        $this->leaving = $this->s11Troopers[0];
        $this->joining = $this->sbTroopers[0];
        app(TrooperRepository::class)->updatePrimarySections([$this->leaving->cav_id => $this->sb->id, $this->joining->cav_id => $this->s11->id]);
        CarbonImmutable::setTestNow('2026-10-10 12:00:00');
    });

    it('does not show the old leader a running-week post of a trooper who has left their section', function () {
        Loa::factory()->needsReview()->create(['trooper_id' => $this->leaving->id, 'posted_at' => '2026-10-10 09:00:00']);

        dashboard($this->sectionLeader)(fn ($page) => $page->where('attention.totals.loaReview', 0));
    });

    it('still shows the old leader a closing-week post of that trooper, on the rollcall that lists them that week', function () {
        Loa::factory()->needsReview()->create(['trooper_id' => $this->leaving->id, 'posted_at' => '2026-10-04 12:00:00']);

        dashboard($this->sectionLeader)(fn ($page) => $page
            ->where('attention.totals.loaReview', 1)
            ->where('attention.items.0.kind', 'loa_review')
            ->where('attention.items.0.trooper.id', $this->leaving->id)
            ->where('attention.items.0.section.designation', '1/1/A/1-7')
            ->where('attention.items.0.href', route('rollcall.show', ['unit' => $this->s11->id, 'week' => '2026-10-03', 'focus' => $this->leaving->id])));
    });

    it('shows the new leader a running-week post of a trooper who joined their section this week', function () {
        Loa::factory()->needsReview()->create(['trooper_id' => $this->joining->id, 'posted_at' => '2026-10-10 09:00:00']);

        dashboard($this->sectionLeader)(fn ($page) => $page
            ->where('attention.totals.loaReview', 1)
            ->where('attention.items.0.trooper.id', $this->joining->id)
            ->where('attention.items.0.section.designation', '1/1/A/1-7')
            ->where('attention.items.0.href', route('rollcall.show', ['unit' => $this->s11->id, 'week' => '2026-10-10', 'focus' => $this->joining->id])));
    });
});
