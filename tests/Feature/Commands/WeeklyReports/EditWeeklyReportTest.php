<?php

use App\Commands\Attendance\AttendanceMark;
use App\Commands\Attendance\MarkAttendance;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Commands\Attendance\RollcallWeekLockedException;
use App\Commands\WeeklyReports\ReopenWeeklyReport;
use App\Commands\WeeklyReports\ReopenWeeklyReportHandler;
use App\Commands\WeeklyReports\SendWeeklyReport;
use App\Commands\WeeklyReports\SendWeeklyReportForbiddenException;
use App\Commands\WeeklyReports\SendWeeklyReportHandler;
use App\Commands\WeeklyReports\SentWeeklyReport;
use App\Commands\WeeklyReports\WeeklyReportLockedException;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\ReasonRequiredException;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

// A sent report can be edited until it locks at its deadline (2359Z on the Saturday after the week): Edit report
// reopens it with a reason, the week's rollcall opens again, and sending it again freezes a new snapshot.

beforeEach(function () {
    $this->platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'callsign' => '1/A/1-7', 'path' => '800/']);
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'callsign' => '1/1/A/1-7', 'parent_id' => $this->platoon->id, 'path' => '800/801/']);
    $this->leader = User::factory()->commanding($this->section)->create();
    $this->platoonLeader = User::factory()->commanding($this->platoon)->create();

    $this->weekStart = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
    // Due Saturday 2026-10-10 2359Z.
    $this->due = CarbonImmutable::parse('2026-10-10 23:59:00', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 18:00:00', 'UTC'));

    $this->troopers = Trooper::factory()->count(2)->create(['primary_section_id' => $this->section->id]);
    confirmPracticeHeld($this->section, $this->weekStart);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function editSend(User $actor, Unit $unit, ?string $reason = 'Weekly report', ?string $notes = 'All good.'): SentWeeklyReport
{
    // A mark fixed while the report is reopened clears the confirmation, which a re-send needs again (#634).
    confirmPracticeHeld($unit, '2026-10-03');

    return app(SendWeeklyReportHandler::class)->handle($actor, new SendWeeklyReport($unit->id, CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'), $reason, $notes));
}

function editReopen(User $actor, Unit $unit, ?string $reason = 'Wrong marks'): WeeklyReport
{
    return app(ReopenWeeklyReportHandler::class)->handle($actor, new ReopenWeeklyReport($unit->id, CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'), $reason));
}

function editMark(User $actor, Unit $section, Trooper $trooper, AttendanceStatus $status): void
{
    app(MarkAttendanceHandler::class)->handle($actor, new MarkAttendance($section->id, CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'), new AttendanceMark($trooper->id, $status)));
}

it('reopens a sent report with a reason, unlocking the rollcall until it is sent again', function () {
    editSend($this->leader, $this->section);
    $reports = app(WeeklyReportRepository::class);

    expect($reports->isSentFor($this->section->id, $this->weekStart))->toBeTrue()
        ->and(fn () => editMark($this->leader, $this->section, $this->troopers[0], AttendanceStatus::Present))->toThrow(RollcallWeekLockedException::class);

    $reopened = editReopen($this->leader, $this->section);

    expect($reopened->reopened_at)->not->toBeNull()
        ->and($reopened->reopened_by)->toBe($this->leader->id)
        ->and($reopened->status->value)->toBe('sent')
        ->and($reports->isSentFor($this->section->id, $this->weekStart))->toBeFalse();

    // The rollcall can be fixed while it is reopened.
    editMark($this->leader, $this->section, $this->troopers[0], AttendanceStatus::Present);

    $result = editSend($this->leader, $this->section, 'Fixed the marks', 'Corrected.');
    $report = WeeklyReport::query()->sole();

    expect($result->alreadySent)->toBeFalse()
        ->and($report->notes)->toBe('Corrected.')
        ->and($report->reopened_at)->toBeNull()
        ->and($report->revisions)->toHaveCount(1)
        ->and($report->revisions[0]['notes'])->toBe('All good.')
        ->and($report->revisions[0]['content_digest'])->not->toBe($report->content_digest)
        ->and($reports->isSentFor($this->section->id, $this->weekStart))->toBeTrue()
        ->and(fn () => editMark($this->leader, $this->section, $this->troopers[1], AttendanceStatus::Present))->toThrow(RollcallWeekLockedException::class);

    expect(AuditLogEntry::query()->whereIn('action', ['weekly_report.sent', 'weekly_report.reopened', 'weekly_report.resent'])->orderBy('id')->get(['action', 'reason'])->toArray())
        ->toBe([
            ['action' => 'weekly_report.sent', 'reason' => 'Weekly report'],
            ['action' => 'weekly_report.reopened', 'reason' => 'Wrong marks'],
            ['action' => 'weekly_report.resent', 'reason' => 'Fixed the marks'],
        ]);
});

it('requires a reason to reopen, and writes nothing without one', function (?string $reason) {
    editSend($this->leader, $this->section);

    expect(fn () => editReopen($this->leader, $this->section, $reason))->toThrow(ReasonRequiredException::class);

    expect(WeeklyReport::query()->sole()->reopened_at)->toBeNull()
        ->and(AuditLogEntry::query()->where('action', 'weekly_report.reopened')->count())->toBe(0);
})->with(['null' => [null], 'blank' => ['  ']]);

it('lets anyone who could send it reopen it, not only the sender, and nobody outside scope', function () {
    editSend($this->leader, $this->section);

    // The platoon leader can send the section's report, so can reopen it.
    expect(editReopen($this->platoonLeader, $this->section)->reopened_by)->toBe($this->platoonLeader->id);

    $outsider = User::factory()->commanding(Unit::factory()->create(['type' => UnitType::Section, 'path' => '900/901/']))->create();

    expect(fn () => editReopen($outsider, $this->section))->toThrow(SendWeeklyReportForbiddenException::class);
});

it('refuses to reopen a report that was never sent', function () {
    expect(fn () => editReopen($this->leader, $this->section))->toThrow(WeeklyReportLockedException::class);
});

it('can be reopened up to the deadline minute and not after', function () {
    editSend($this->leader, $this->section);

    CarbonImmutable::setTestNow($this->due->addSecond());
    expect(fn () => editReopen($this->leader, $this->section))
        ->toThrow(WeeklyReportLockedException::class, "locked at its deadline, 10OCT26 23\u{200A}59Z");

    CarbonImmutable::setTestNow($this->due);
    expect(editReopen($this->leader, $this->section)->reopened_at)->not->toBeNull();
});

it('locks a reopened report that was not sent again once the deadline passes, as it was', function () {
    editSend($this->leader, $this->section);
    editReopen($this->leader, $this->section);
    $reports = app(WeeklyReportRepository::class);

    expect($reports->isSentFor($this->section->id, $this->weekStart))->toBeFalse();

    CarbonImmutable::setTestNow($this->due->addMinute());

    expect($reports->isSentFor($this->section->id, $this->weekStart))->toBeTrue()
        ->and(fn () => editMark($this->leader, $this->section, $this->troopers[0], AttendanceStatus::Present))->toThrow(RollcallWeekLockedException::class);

    $late = editSend($this->leader, $this->section, 'Too late', 'Changed.');

    expect($late->alreadySent)->toBeTrue()
        ->and(WeeklyReport::query()->sole()->notes)->toBe('All good.')
        ->and(WeeklyReport::query()->sole()->revisions)->toBeNull();
});

it('keeps a sent report that was not reopened a no-op on a second send', function () {
    editSend($this->leader, $this->section);

    expect(editSend($this->leader, $this->section, 'Again', 'Changed.')->alreadySent)->toBeTrue()
        ->and(WeeklyReport::query()->sole()->notes)->toBe('All good.');
});

it('tells the parent a child changed its report after the parent was sent, and leaves its numbers alone', function () {
    editSend($this->leader, $this->section);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 10:00:00', 'UTC'));
    editSend($this->platoonLeader, $this->platoon);
    $platoonKpis = WeeklyReport::query()->where('unit_id', $this->platoon->id)->sole()->kpis;

    $page = fn () => $this->actingAs($this->platoonLeader)->get("/reports/{$this->platoon->id}/2026-10-03");
    $page()->assertInertia(fn (AssertableInertia $p) => $p->where('report.changedChildren', [])->where('report.canEdit', true));

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));
    editReopen($this->leader, $this->section);
    editMark($this->leader, $this->section, $this->troopers[0], AttendanceStatus::Present);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 12:05:00', 'UTC'));
    editSend($this->leader, $this->section, 'Fixed', 'Corrected.');

    $page()->assertInertia(fn (AssertableInertia $p) => $p
        ->where('report.changedChildren.0.id', $this->section->id)
        ->has('report.changedChildren', 1));

    expect(WeeklyReport::query()->where('unit_id', $this->platoon->id)->sole()->kpis)->toBe($platoonKpis);

    // The platoon leader edits and sends again: the notice goes, the roll-up takes the new numbers.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 13:00:00', 'UTC'));
    editReopen($this->platoonLeader, $this->platoon);
    editSend($this->platoonLeader, $this->platoon, 'Section fixed its report');

    $page()->assertInertia(fn (AssertableInertia $p) => $p->where('report.changedChildren', []));
});

it('shows Edit report only before the deadline, and reopens through the page with a reason', function () {
    editSend($this->leader, $this->section);
    $url = "/reports/{$this->section->id}/2026-10-03";

    $this->actingAs($this->leader)->post("{$url}/edit", ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->leader)->post("{$url}/edit", ['reason' => 'Wrong marks'])->assertRedirect($url);

    $this->actingAs($this->leader)->get($url)->assertInertia(fn (AssertableInertia $p) => $p
        ->where('report.status', 'sent')
        ->where('report.editing', true)
        ->where('report.canEdit', false)
        ->whereNot('troopers', null));

    editSend($this->leader, $this->section, 'Fixed');
    CarbonImmutable::setTestNow($this->due->addMinute());

    $this->actingAs($this->leader)->get($url)->assertInertia(fn (AssertableInertia $p) => $p->where('report.canEdit', false)->where('report.editing', false));
    $this->actingAs($this->leader)->post("{$url}/edit", ['reason' => 'Late'])->assertSessionHasErrors('report');
});

it('lists the versions an edit replaced in History, with who reopened and sent it again and why', function () {
    editSend($this->leader, $this->section);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));
    editReopen($this->leader, $this->section, 'Wrong marks');
    // A second reopen while open changes nothing: the first one is the one that opened it.
    editReopen($this->platoonLeader, $this->section, 'Also wrong');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 12:05:00', 'UTC'));
    editSend($this->leader, $this->section, 'Fixed the marks', 'Corrected.');
    $url = "/reports/{$this->section->id}/2026-10-03";

    $this->actingAs($this->platoonLeader)->get($url)->assertInertia(fn (AssertableInertia $p) => $p
        ->has('report.revisions', 1)
        ->where('report.revisions.0.sentAt', '2026-10-07T18:00:00+00:00')
        ->where('report.revisions.0.notes', 'All good.')
        ->where('report.revisions.0.reopened.at', '2026-10-08T12:00:00+00:00')
        ->where('report.revisions.0.reopened.reason', 'Wrong marks')
        ->where('report.revisions.0.replaced.at', '2026-10-08T12:05:00+00:00')
        ->where('report.revisions.0.replaced.reason', 'Fixed the marks')
        ->whereNot('report.revisions.0.kpis', null)
        ->where('report.notes', 'Corrected.'));

    // Someone who cannot read the section's report does not reach its history either.
    $outsider = User::factory()->commanding(Unit::factory()->create(['type' => UnitType::Section, 'path' => '900/901/']))->create();
    $this->actingAs($outsider)->get($url)->assertForbidden();
});

it('lists no earlier versions for a report never edited', function () {
    editSend($this->leader, $this->section);

    $this->actingAs($this->leader)->get("/reports/{$this->section->id}/2026-10-03")
        ->assertInertia(fn (AssertableInertia $p) => $p->where('report.revisions', []));
});
