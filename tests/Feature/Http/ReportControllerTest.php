<?php

use App\Commands\WeeklyReports\SaveReportDraftHandler;
use App\Commands\WeeklyReports\SendWeeklyReportHandler;
use App\Kpi\Ratio;
use App\Kpi\ReportSnapshot;
use App\Kpi\UnitKpis;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\RollcallSignature;
use App\Models\SyncRun;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\WeeklyReportRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia;

// 5.3–5.5: the report compose screen — a live draft for a section, the frozen snapshot once sent,
// a non-leaf roll-up, and the audited draft-save and send behind it. Scope-gated (AD-8).

beforeEach(function () {
    // Thursday 2026-10-08: the open Cav week started Saturday 2026-10-03.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $this->week = '2026-10-03';
    $this->weekStart = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');

    $this->platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'name' => '1st Platoon', 'callsign' => '1/A/1-7', 'path' => '700/']);
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'name' => '2nd Section', 'callsign' => '2/1/A/1-7', 'parent_id' => $this->platoon->id, 'path' => '700/701/']);
    $this->sibling = Unit::factory()->create(['type' => UnitType::Section, 'callsign' => '3/1/A/1-7', 'parent_id' => $this->platoon->id, 'path' => '700/702/']);

    // The section's leader by billet: a command billet is what writes, a staff grant only reads.
    $this->leader = User::factory()->create(['username' => 'Vasquez.R', 'cav_id' => 501]);
    UnitCommandMapping::factory()->create(['unit_id' => $this->section->id, 'cav_position_id' => 5501]);
    Trooper::factory()->create(['cav_id' => 501, 'rank_short' => 'SGT', 'username' => 'Vasquez.R', 'primary_section_id' => $this->section->id, 'cav_position_id' => 5501]);

    $this->trooper = Trooper::factory()->create(['rank_short' => 'PFC', 'username' => null, 'primary_section_id' => $this->section->id]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function reportUrl(Unit $unit, string $week, string $suffix = ''): string
{
    return "/reports/{$unit->id}/{$week}{$suffix}";
}

function markSp(Unit $section, Trooper $trooper, CarbonImmutable $week, AttendanceStatus $status, RollcallSignature $sig = RollcallSignature::WillAttend): void
{
    $practice = app(SectionPracticeRepository::class)->findOrCreate($section->id, $week);
    app(AttendanceRepository::class)->signRollcall($practice, $trooper->id, $sig);
    app(AttendanceRepository::class)->markPractice($practice, $trooper->id, $status, AttendanceSource::Manual);
}

function frozenSnapshot(int $strength = 2): array
{
    return (new ReportSnapshot(new UnitKpis($strength, new Ratio(6, 8), new Ratio(3, 4), 0, 0, 0), null))->toArray();
}

it('renders a section\'s live draft with its KPIs, troopers and a first-report baseline', function () {
    markSp($this->section, $this->trooper, $this->weekStart, AttendanceStatus::Missed);

    $this->actingAs($this->leader)->get(reportUrl($this->section, $this->week))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Reports/Show')
            ->where('unit.isLeaf', true)
            ->where('unit.parent.callsign', '1/A/1-7')
            ->where('report.status', 'none')
            ->where('kpis.strength', 2)
            ->where('baseline.kind', 'first')
            ->where('delta', null)
            ->has('troopers', 2)
            ->where('troopers.1.name', "PFC #{$this->trooper->cav_id}")
            ->where('troopers.1.status', 'missed')
            ->where('troopers.1.rollcall', 'will_attend')
            ->where('troopers.1.source', 'manual')
            ->where('troopers.0.name', 'SGT Vasquez.R')
            ->where('troopers.0.username', 'Vasquez.R')
            ->where('troopers.0.rankShort', 'SGT')
            ->where('troopers.0.status', null)
            ->has('trend', 8)
            ->has('reasons', 5)
            ->where('week.next', null)
            ->where('week.previous', '2026-09-26'));

    expect(WeeklyReport::query()->exists())->toBeFalse(); // viewing never persists (AD-12)
});

it('renders zeroed KPIs, not an error, when no attendance is marked yet', function () {
    $this->actingAs($this->leader)->get(reportUrl($this->section, $this->week))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('kpis.sp.denominator', 0)
            ->where('kpis.makeups', 0)
            ->where('kpiError', null));
});

it('shows the delta against the prior week\'s sent report', function () {
    app(WeeklyReportRepository::class)->send($this->section->id, $this->weekStart->subWeek(), frozenSnapshot(5), null, $this->leader->id, 'd', null);

    $this->actingAs($this->leader)->get(reportUrl($this->section, $this->week))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('baseline.kind', 'prior')
            ->where('delta.strength', -3)
            ->has('history', 1)
            ->where('history.0.sentBy', 'SGT Vasquez.R'));
});

it('shows "no prior baseline" when only an older week was sent', function () {
    app(WeeklyReportRepository::class)->send($this->section->id, $this->weekStart->subWeeks(2), frozenSnapshot(), null, $this->leader->id, 'd', null);
    app(WeeklyReportRepository::class)->saveDraft($this->section->id, $this->weekStart->subWeek(), [], null);

    $this->actingAs($this->leader)->get(reportUrl($this->section, $this->week))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('baseline.kind', 'no_prior')
            ->where('baseline.week', $this->weekStart->subWeeks(2)->toIso8601String())
            ->where('delta', null)
            ->where('trend.5.rc', ['numerator' => 6, 'denominator' => 8])
            ->where('trend.6.rc', null));
});

it('renders a sent report from its frozen snapshot, never recomputed, with who sent it', function () {
    markSp($this->section, $this->trooper, $this->weekStart, AttendanceStatus::Present);
    $this->actingAs($this->leader)->post(reportUrl($this->section, $this->week, '/send'), [
        'reason' => 'Weekly report', 'notes' => 'Good week.', 'training_subject' => 'Room clearing',
    ])->assertRedirect(reportUrl($this->section, $this->week))->assertSessionHasNoErrors();

    markSp($this->section, $this->trooper, $this->weekStart, AttendanceStatus::Missed); // after the freeze

    $this->actingAs($this->leader)->get(reportUrl($this->section, $this->week))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.status', 'sent')
            ->where('report.sentBy', 'SGT Vasquez.R')
            ->where('report.trainingSubject', 'Room clearing')
            ->where('kpis.sp', ['numerator' => 1, 'denominator' => 2])
            ->where('troopers', null));
});

it('refuses a unit outside the viewer\'s scope, or one that does not exist, with 403', function () {
    $this->actingAs($this->leader)->get(reportUrl($this->sibling, $this->week))->assertForbidden();
    $this->actingAs($this->leader)->get("/reports/999999/{$this->week}")->assertForbidden();
    $this->actingAs($this->leader)->put(reportUrl($this->sibling, $this->week), ['notes' => 'x'])->assertForbidden();
    $this->actingAs($this->leader)->post(reportUrl($this->sibling, $this->week, '/send'), ['reason' => 'x'])->assertForbidden();

    expect(WeeklyReport::query()->exists())->toBeFalse();
});

it('answers 404 for a week that is not a Saturday, not a date, or still in the future', function (string $week) {
    $this->actingAs($this->leader)->get(reportUrl($this->section, $week))->assertNotFound();
})->with(['2026-10-04', '2026-02-30', '2026-10-10']);

it('saves the draft\'s notes, subject and unaccounted reasons through the audited command', function () {
    $this->actingAs($this->leader)->put(reportUrl($this->section, $this->week), [
        'notes' => 'Comms tighter.',
        'training_subject' => 'Bounding overwatch',
        'unaccounted' => [['trooper_id' => $this->trooper->id, 'reason' => 'sick']],
    ])->assertRedirect(reportUrl($this->section, $this->week))->assertSessionHasNoErrors();

    $report = WeeklyReport::query()->sole();
    expect($report->status)->toBe(WeeklyReportStatus::Draft)
        ->and($report->notes)->toBe('Comms tighter.')
        ->and($report->training_subject)->toBe('Bounding overwatch')
        ->and($report->unaccounted)->toBeIgnoringKeyOrder([['trooper_id' => $this->trooper->id, 'reason' => 'sick']])
        ->and(AuditLogEntry::query()->sole()->action)->toBe(SaveReportDraftHandler::ACTION);
});

it('turns a reason for a trooper outside the section into a form error, saving nothing', function () {
    $this->actingAs($this->leader)->put(reportUrl($this->section, $this->week), [
        'unaccounted' => [['trooper_id' => Trooper::factory()->create()->id, 'reason' => 'sick']],
    ])->assertSessionHasErrors('unaccounted');

    expect(WeeklyReport::query()->exists())->toBeFalse();
});

it('refuses to edit a sent report, as a form error', function () {
    app(WeeklyReportRepository::class)->send($this->section->id, $this->weekStart, frozenSnapshot(), 'Frozen.', $this->leader->id, 'd', null);

    $this->actingAs($this->leader)->put(reportUrl($this->section, $this->week), ['notes' => 'Changed'])
        ->assertSessionHasErrors('report');

    expect(WeeklyReport::query()->sole()->notes)->toBe('Frozen.');
});

it('requires a reason to send, changing nothing without one', function () {
    $this->actingAs($this->leader)->post(reportUrl($this->section, $this->week, '/send'), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect(WeeklyReport::query()->exists())->toBeFalse()
        ->and(AuditLogEntry::query()->exists())->toBeFalse();
});

it('records the send with its reason in the audit log', function () {
    $this->actingAs($this->leader)->post(reportUrl($this->section, $this->week, '/send'), [
        'reason' => 'Weekly report (sent with open items: Training subject filled)',
    ])->assertSessionHasNoErrors();

    $entry = AuditLogEntry::query()->sole();
    expect($entry->action)->toBe(SendWeeklyReportHandler::ACTION)
        ->and($entry->reason)->toBe('Weekly report (sent with open items: Training subject filled)');
});

it('renders a non-leaf unit as the roll-up of its children, naming those not sent', function () {
    $platoonLeader = User::factory()->commanding($this->platoon)->create();
    app(WeeklyReportRepository::class)->send($this->sibling->id, $this->weekStart, frozenSnapshot(4), null, $platoonLeader->id, 'd', null, 'Land navigation');

    $this->actingAs($platoonLeader)->get(reportUrl($this->platoon, $this->week))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('unit.isLeaf', false)
            ->where('troopers', null)
            ->has('children', 2)
            ->where('children.0.status', 'not_started')
            ->where('children.1.status', 'sent')
            ->where('children.1.trainingSubject', 'Land navigation')
            ->where('kpis.strength', 4)
            ->where('missingChildren', [$this->section->id]));
});

it('lets a platoon leader send with a child missing, freezing who was missing', function () {
    $platoonLeader = User::factory()->commanding($this->platoon)->create();

    $this->actingAs($platoonLeader)->post(reportUrl($this->platoon, $this->week, '/send'), ['reason' => 'Weekly report'])
        ->assertSessionHasNoErrors();

    $report = WeeklyReport::query()->where('unit_id', $this->platoon->id)->sole();
    expect(ReportSnapshot::fromArray($report->kpis)->missingChildren)->toBe([$this->section->id, $this->sibling->id]);
});

it('lets an admin open any unit\'s report', function () {
    $this->actingAs(User::factory()->admin()->create())->get(reportUrl($this->sibling, $this->week))->assertOk();
});

it('sends the trooper names as a JSON object even when the section has no troopers', function () {
    $response = $this->actingAs(User::factory()->admin()->create())->get(reportUrl($this->sibling, $this->week))->assertOk();

    // The page declares `names` as an Object; an empty collection would encode as `[]`.
    expect(json_encode($response->viewData('page')['props']['names']))->toBe('{}');
});

it('keeps a sent report reading as it froze: its baseline and delta never change later', function () {
    $this->actingAs($this->leader)->post(reportUrl($this->section, $this->week, '/send'), ['reason' => 'Weekly report'])->assertSessionHasNoErrors();
    // The prior week is sent late, after this one froze; a trooper joins after the freeze.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());
    app(WeeklyReportRepository::class)->send($this->section->id, $this->weekStart->subWeek(), frozenSnapshot(5), null, $this->leader->id, 'd', null);
    Trooper::factory()->create(['primary_section_id' => $this->section->id]);

    $this->actingAs($this->leader)->get(reportUrl($this->section, $this->week))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.status', 'sent')
            ->where('delta', null)
            ->where('baseline.kind', 'first')
            ->where('kpis.strength', 2));
});

it('freezes the delta, including the rate deltas, as computed at send', function () {
    app(WeeklyReportRepository::class)->send($this->section->id, $this->weekStart->subWeek(), frozenSnapshot(5), null, $this->leader->id, 'd', null);
    markSp($this->section, $this->trooper, $this->weekStart, AttendanceStatus::Present);
    $this->actingAs($this->leader)->post(reportUrl($this->section, $this->week, '/send'), ['reason' => 'Weekly report'])->assertSessionHasNoErrors();
    Trooper::factory()->create(['primary_section_id' => $this->section->id]); // after the freeze

    $this->actingAs($this->leader)->get(reportUrl($this->section, $this->week))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('baseline.kind', 'prior')
            ->where('delta.strength', -3)
            ->where('delta.spBp', -2500) // 1/2 now vs 3/4 then
            ->has('delta.rcBp'));
});

it('renders an unreadable prior snapshot as a page-level error, and refuses the send but not the draft', function () {
    app(WeeklyReportRepository::class)->send($this->section->id, $this->weekStart->subWeek(), ['corrupt' => true], null, $this->leader->id, 'd', null);

    $this->actingAs($this->leader)->get(reportUrl($this->section, $this->week))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('kpis', null)
            ->where('kpiError', fn ($error) => str_contains($error, "Last week's sent report cannot be read"))
            ->where('history.0.kpis', null)
            ->has('trend', 8));

    $this->actingAs($this->leader)->post(reportUrl($this->section, $this->week, '/send'), ['reason' => 'Weekly report'])
        ->assertSessionHasErrors('report');
    expect(WeeklyReport::query()->where('week_start', $this->weekStart)->exists())->toBeFalse();

    $this->actingAs($this->leader)->put(reportUrl($this->section, $this->week), ['notes' => 'Kept.'])->assertSessionHasNoErrors();
    expect(WeeklyReport::query()->where('week_start', $this->weekStart)->sole()->notes)->toBe('Kept.');
});

it('renders a damaged sent report as an error rather than failing the page', function () {
    app(WeeklyReportRepository::class)->send($this->section->id, $this->weekStart, ['corrupt' => true], null, $this->leader->id, 'd', null);

    $this->actingAs($this->leader)->get(reportUrl($this->section, $this->week))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('kpis', null)->whereNot('kpiError', null));
});

it('explains a roll-up with no units under it, and refuses to send it', function () {
    $company = Unit::factory()->create(['type' => UnitType::Company, 'path' => '800/']);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(reportUrl($company, $this->week))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('kpiError', 'This unit has no units under it, so there are no reports to roll up.'));
    $this->actingAs($admin)->post(reportUrl($company, $this->week, '/send'), ['reason' => 'Weekly report'])
        ->assertSessionHasErrors('report');
});

it('tells the leader when the report was already sent, leaving the frozen content as it was', function () {
    $this->actingAs($this->leader)->post(reportUrl($this->section, $this->week, '/send'), ['reason' => 'Weekly report', 'notes' => 'First.']);

    $this->actingAs($this->leader)->post(reportUrl($this->section, $this->week, '/send'), ['reason' => 'Weekly report', 'notes' => 'Second.'])
        ->assertSessionHasErrors('report');

    expect(WeeklyReport::query()->sole()->notes)->toBe('First.')
        ->and(AuditLogEntry::query()->count())->toBe(1);
});

it('shows a sent roll-up\'s children as they froze, not as they stand now', function () {
    $platoonLeader = User::factory()->commanding($this->platoon)->create();
    $this->actingAs($platoonLeader)->post(reportUrl($this->platoon, $this->week, '/send'), ['reason' => 'Weekly report']);
    app(WeeklyReportRepository::class)->send($this->sibling->id, $this->weekStart, frozenSnapshot(4), null, $platoonLeader->id, 'd', null); // sent late

    $this->actingAs($platoonLeader)->get(reportUrl($this->platoon, $this->week))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('children.1.status', 'missing')
            ->where('children.1.kpis', null)
            ->where('kpis.strength', 0));
});

it('never widens scope upward: a section leader cannot open their platoon', function () {
    $this->actingAs($this->leader)->get(reportUrl($this->platoon, $this->week))->assertForbidden();
});

it('narrows to the viewed-as unit and refuses writes while viewing as (AD-9)', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section->id])->assertSessionHasNoErrors();

    $this->get(reportUrl($this->section, $this->week))->assertOk();
    $this->get(reportUrl($this->sibling, $this->week))->assertForbidden();
    $this->put(reportUrl($this->section, $this->week), ['notes' => 'x'])->assertForbidden();

    expect(WeeklyReport::query()->exists())->toBeFalse();
});

it('carries the roster source and its last sync time (AD-20)', function () {
    $run = SyncRun::factory()->create(['finished_at' => CarbonImmutable::parse('2026-10-08 09:14:00', 'UTC')]);

    $this->actingAs($this->leader)->get(reportUrl($this->section, $this->week))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rosterSync.source', 'Cav roster (api.7cav.us)')
            ->where('rosterSync.at', $run->finished_at->toIso8601String()));
});

it('does not claim who was in a sent roll-up whose snapshot is damaged', function () {
    $admin = User::factory()->admin()->create();
    app(WeeklyReportRepository::class)->send($this->platoon->id, $this->weekStart, ['corrupt' => true], null, $admin->id, 'd', null);

    $this->actingAs($admin)->get(reportUrl($this->platoon, $this->week))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('children', null)->whereNot('kpiError', null));
});

it('renders a roll-up whose child sent an unreadable snapshot as unavailable, naming the child, and refuses the send but not the draft', function () {
    Exceptions::fake();
    $platoonLeader = User::factory()->commanding($this->platoon)->create();
    app(WeeklyReportRepository::class)->send($this->sibling->id, $this->weekStart, ['corrupt' => true], null, $platoonLeader->id, 'd', null);

    $this->actingAs($platoonLeader)->get(reportUrl($this->platoon, $this->week))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('kpis', null)
            ->where('kpiError', "3/1/A/1-7's sent report cannot be read, so this roll-up cannot be computed. An admin needs to look at it.")
            ->where('children.0.id', $this->section->id)
            ->where('children.0.status', 'not_started')
            ->where('children.1.id', $this->sibling->id)
            ->where('children.1.status', 'sent')
            ->where('children.1.kpis', null));
    Exceptions::assertReportedCount(1);

    $this->actingAs($platoonLeader)->post(reportUrl($this->platoon, $this->week, '/send'), ['reason' => 'Weekly report'])
        ->assertSessionHasErrors('report');
    expect(WeeklyReport::query()->where('unit_id', $this->platoon->id)->exists())->toBeFalse();

    $this->actingAs($platoonLeader)->put(reportUrl($this->platoon, $this->week), ['notes' => 'Kept.'])->assertSessionHasNoErrors();
    expect(WeeklyReport::query()->where('unit_id', $this->platoon->id)->sole()->notes)->toBe('Kept.');
});
