<?php

use App\Commands\WeeklyReports\SaveReportDraftHandler;
use App\Commands\WeeklyReports\SendWeeklyReportHandler;
use App\Kpi\Ratio;
use App\Kpi\ReportSnapshot;
use App\Kpi\UnitKpis;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\RollcallSignature;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\WeeklyReportRepository;
use Carbon\CarbonImmutable;
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

    $this->leader = User::factory()->create(['username' => 'Vasquez.R', 'cav_id' => 501]);
    Trooper::factory()->create(['cav_id' => 501, 'rank' => 'SGT', 'primary_section_id' => $this->section->id]);
    PortalRoleGrant::factory()->create(['user_id' => $this->leader->id, 'unit_id' => $this->section->id]);

    $this->trooper = Trooper::factory()->create(['rank' => 'PFC', 'primary_section_id' => $this->section->id]);
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
        ->and($report->unaccounted)->toBe([['trooper_id' => $this->trooper->id, 'reason' => 'sick']])
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
    $platoonLeader = User::factory()->create();
    PortalRoleGrant::factory()->create(['user_id' => $platoonLeader->id, 'unit_id' => $this->platoon->id]);
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
    $platoonLeader = User::factory()->create();
    PortalRoleGrant::factory()->create(['user_id' => $platoonLeader->id, 'unit_id' => $this->platoon->id]);

    $this->actingAs($platoonLeader)->post(reportUrl($this->platoon, $this->week, '/send'), ['reason' => 'Weekly report'])
        ->assertSessionHasNoErrors();

    $report = WeeklyReport::query()->where('unit_id', $this->platoon->id)->sole();
    expect(ReportSnapshot::fromArray($report->kpis)->missingChildren)->toBe([$this->section->id, $this->sibling->id]);
});

it('lets an admin open any unit\'s report', function () {
    $this->actingAs(User::factory()->admin()->create())->get(reportUrl($this->sibling, $this->week))->assertOk();
});
