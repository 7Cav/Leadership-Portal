<?php

use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\RollcallSignature;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

// A trooper who asked for a make-up on the roll call and still has none recorded when the section's report is sent:
// the send asks the leader whether they made it up. A yes records Make-up complete before the snapshot freezes; a no
// leaves the mark as it is. Both answers are frozen with the report.

function mcTrooper(Unit $section): Trooper
{
    $trooper = Trooper::factory()->create(['tis' => '2015-03-01']);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

function mcMark(Trooper $trooper, Unit $section, AttendanceStatus $status, RollcallSignature $rollcall): void
{
    $practice = app(SectionPracticeRepository::class)->findOrCreate($section->id, CarbonImmutable::parse('2026-10-03', 'UTC'));
    app(AttendanceRepository::class)->markPractice($practice, $trooper->id, $status, AttendanceSource::Manual);
    app(AttendanceRepository::class)->signRollcall($practice, $trooper->id, $rollcall);
}

function mcSend(object $test, array $checks = [])
{
    return $test->actingAs($test->leader)->post("/reports/{$test->section->id}/2026-10-03/send", [
        'reason' => 'Weekly report',
        'notes' => '',
        'makeup_checks' => $checks,
    ]);
}

function mcReport(Unit $section): ?WeeklyReport
{
    return WeeklyReport::query()->where('unit_id', $section->id)->first();
}

function mcStatus(Trooper $trooper): ?AttendanceStatus
{
    $week = CarbonImmutable::parse('2026-10-03', 'UTC');

    return app(AttendanceRepository::class)->countingMarksOf([$trooper->id], $week, $week)->sole()->status;
}

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $units = app(UnitRepository::class);
    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $units->create(UnitType::Company, 'Alpha', 'A/1-7', $battalion->id);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $this->section = $units->create(UnitType::Section, 'Section A', '1/1/A/1-7', $platoon->id);
    $this->leader = User::factory()->commanding($this->section)->create();

    $this->present = mcTrooper($this->section);
    mcMark($this->present, $this->section, AttendanceStatus::Present, RollcallSignature::WillAttend);
    $this->owing = mcTrooper($this->section);
    mcMark($this->owing, $this->section, AttendanceStatus::Missed, RollcallSignature::MakeupRequested);
});

afterEach(fn () => Carbon::setTestNow());

it('flags the owing trooper on the report page so the send can ask about them', function () {
    $this->actingAs($this->leader)->get("/reports/{$this->section->id}/2026-10-03")->assertOk()->assertInertia(fn ($page) => $page
        ->where('troopers', fn ($rows) => collect($rows)->firstWhere('id', $this->owing->id)['owesMakeup'] === true
            && collect($rows)->firstWhere('id', $this->present->id)['owesMakeup'] === false));
});

it('refuses to send until every owing trooper is answered, and writes nothing', function () {
    mcSend($this)->assertSessionHasErrors('makeup_checks');

    expect(mcReport($this->section)?->status)->not->toBe(WeeklyReportStatus::Sent)
        ->and(mcStatus($this->owing))->toBe(AttendanceStatus::Missed);
});

it('refuses an answer for a trooper who owes no make-up', function () {
    mcSend($this, [
        ['trooper_id' => $this->owing->id, 'done' => false],
        ['trooper_id' => $this->present->id, 'done' => true],
    ])->assertSessionHasErrors('makeup_checks');

    expect(mcReport($this->section)?->status)->not->toBe(WeeklyReportStatus::Sent);
});

it('records Make-up complete through the audited attendance command on a yes, before the snapshot freezes', function () {
    mcSend($this, [['trooper_id' => $this->owing->id, 'done' => true]])->assertSessionHasNoErrors();

    $report = mcReport($this->section);
    $week = CarbonImmutable::parse('2026-10-03', 'UTC');
    $mark = app(AttendanceRepository::class)->countingMarksOf([$this->owing->id], $week, $week)->sole();

    expect($report->status)->toBe(WeeklyReportStatus::Sent)
        ->and($report->makeup_checks)->toEqual([['trooper_id' => $this->owing->id, 'done' => true]])
        ->and($mark->status)->toBe(AttendanceStatus::Makeup)
        ->and($mark->source)->toBe(AttendanceSource::Manual)
        // The frozen snapshot counts the make-up.
        ->and($report->kpis['makeups'])->toBe(1)
        ->and(AuditLogEntry::query()->where('action', 'attendance.marked')->count())->toBe(1)
        ->and(AuditLogEntry::query()->where('action', 'weekly_report.sent')->count())->toBe(1);
});

it('keeps the mark on a no, and freezes the answer with the report', function () {
    mcSend($this, [['trooper_id' => $this->owing->id, 'done' => false]])->assertSessionHasNoErrors();

    $report = mcReport($this->section);

    expect($report->status)->toBe(WeeklyReportStatus::Sent)
        ->and($report->makeup_checks)->toEqual([['trooper_id' => $this->owing->id, 'done' => false]])
        ->and(mcStatus($this->owing))->toBe(AttendanceStatus::Missed)
        ->and(AuditLogEntry::query()->where('action', 'attendance.marked')->count())->toBe(0);

    $this->actingAs($this->leader)->get("/reports/{$this->section->id}/2026-10-03")->assertInertia(fn ($page) => $page
        ->where('report.makeupChecks', [['trooper_id' => $this->owing->id, 'done' => false]]));
});

it('asks and stores nothing when nobody owes a make-up', function () {
    mcMark($this->owing, $this->section, AttendanceStatus::Makeup, RollcallSignature::MakeupRequested);

    mcSend($this)->assertSessionHasNoErrors();

    expect(mcReport($this->section)->makeup_checks)->toBeNull();
});
