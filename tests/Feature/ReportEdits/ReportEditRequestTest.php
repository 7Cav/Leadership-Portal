<?php

use App\Authorization\RealActor;
use App\Commands\Attendance\AttendanceMark;
use App\Commands\Attendance\MarkAttendance;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Commands\ReportEdits\DecideReportEdit;
use App\Commands\ReportEdits\DecideReportEditHandler;
use App\Commands\ReportEdits\ReportEditForbiddenException;
use App\Commands\ReportEdits\ReportEditRefusedException;
use App\Commands\ReportEdits\RequestReportEdit;
use App\Commands\ReportEdits\RequestReportEditHandler;
use App\Commands\StaffReports\SendStaffReport;
use App\Commands\StaffReports\SendStaffReportHandler;
use App\Commands\StaffReports\StaffReportRefusedException;
use App\Commands\Units\DeleteUnit;
use App\Commands\Units\UnitHasDependentsException;
use App\Commands\WeeklyReports\ReopenWeeklyReport;
use App\Commands\WeeklyReports\ReopenWeeklyReportHandler;
use App\Commands\WeeklyReports\SendWeeklyReport;
use App\Commands\WeeklyReports\SendWeeklyReportHandler;
use App\Commands\WeeklyReports\WeeklyReportLockedException;
use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\ReportEditRequest;
use App\Models\StaffReport;
use App\Models\Trooper;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\UnitRepository;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../StaffPractices/fixture.php';

// A report that locked at its deadline (2359Z on the Saturday after its week) can be edited again only once a leader
// above approves a request to: the leaders one level up are told in the bell, anyone higher or an Admin may decide
// too, and an approval unlocks it until the coming Saturday 2359Z.

beforeEach(function () {
    $this->tree = staffPracticeTree();
    $this->week = staffPracticeWeek();
    $this->section = $this->tree['units']['host'];
    $this->users = $this->tree['users'];
    Trooper::factory()->create(['primary_section_id' => $this->section->id]);

    // Sent on time: Thursday 08OCT26, before the deadline Sat 10OCT26 2359Z.
    confirmPracticeHeld($this->section, $this->week);
    app(SendWeeklyReportHandler::class)->handle($this->users['sl'], new SendWeeklyReport($this->section->id, $this->week, 'Weekly report', 'The FTX.'));
    app(SendStaffReportHandler::class)->handle($this->users['psg'], new SendStaffReport($this->tree['troopers']['psg']->id, $this->week, false, [], presenceType: 'none'));

    // Monday 12OCT26: both are locked.
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

function askEdit(User $actor, RequestReportEdit $command): ReportEditRequest
{
    return app(RequestReportEditHandler::class)->handle($actor, $command);
}

function decideEdit(User $actor, ReportEditRequest $request, bool $approve, ?string $note = null): ReportEditRequest
{
    return app(DecideReportEditHandler::class)->handle($actor, new DecideReportEdit($request->id, $approve, $note));
}

function unreadBell(User $user): array
{
    return $user->fresh()->unreadNotifications->map(fn ($n) => $n->data['message'])->all();
}

it('refuses a request while the report can still be edited, and an empty reason', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));

    expect(fn () => askEdit($this->users['sl'], RequestReportEdit::weekly($this->section->id, $this->week, 'Wrong week')))
        ->toThrow(ReportEditRefusedException::class, 'can still be edited until 10OCT26');

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'));
    expect(fn () => askEdit($this->users['sl'], RequestReportEdit::weekly($this->section->id, $this->week, '  ')))
        ->toThrow(ReportEditRefusedException::class, 'Say why')
        ->and(ReportEditRequest::query()->count())->toBe(0);
});

it('asks the platoon leader, one level up, and nobody higher, audited against the report', function () {
    $request = askEdit($this->users['sl'], RequestReportEdit::weekly($this->section->id, $this->week, 'Sent into the wrong week'));

    expect($request->status)->toBe(ReportEditRequest::PENDING)
        ->and(unreadBell($this->users['pl']))->toHaveCount(1)
        ->and(unreadBell($this->users['pl'])[0])->toContain('asks to edit 1/1/A/1-7\'s weekly report for 03OCT26 to 09OCT26: Sent into the wrong week')
        ->and(unreadBell($this->users['co']))->toBe([])
        ->and(unreadBell($this->users['admin']))->toBe([])
        ->and(unreadBell($this->users['sl']))->toBe([]);

    $entry = AuditLogEntry::query()->where('action', RequestReportEditHandler::ACTION)->sole();
    expect($entry->actor_id)->toBe($this->users['sl']->id)
        ->and($entry->target_type)->toBe('WeeklyReport')
        ->and($entry->reason)->toBe('Sent into the wrong week');

    // One at a time.
    expect(fn () => askEdit($this->users['sl'], RequestReportEdit::weekly($this->section->id, $this->week, 'Again')))
        ->toThrow(ReportEditRefusedException::class, 'already requested');
});

it('refuses a request from someone who could not send the report', function () {
    $outsider = User::factory()->serving()->create();

    expect(fn () => askEdit($outsider, RequestReportEdit::weekly($this->section->id, $this->week, 'Mine')))->toThrow(ReportEditForbiddenException::class)
        ->and(fn () => askEdit($this->users['sl'], RequestReportEdit::staff($this->tree['troopers']['psg']->id, $this->week, 'Not mine')))->toThrow(ReportEditForbiddenException::class);
});

it('unlocks the report until the coming Saturday 2359Z on approval, and Edit report works again until then', function () {
    $request = askEdit($this->users['sl'], RequestReportEdit::weekly($this->section->id, $this->week, 'Sent into the wrong week'));

    // Nobody decides their own request, and a sibling section's leader is not above it.
    expect(fn () => decideEdit($this->users['sl'], $request, true))->toThrow(ReportEditForbiddenException::class);

    $decided = decideEdit($this->users['pl'], $request, true, 'Go ahead.');
    $until = CarbonImmutable::parse('2026-10-17 23:59:00', 'UTC');

    expect($decided->status)->toBe(ReportEditRequest::APPROVED)
        ->and($decided->unlocked_until->equalTo($until))->toBeTrue()
        ->and(WeeklyReport::query()->where('unit_id', $this->section->id)->sole()->unlocked_until->equalTo($until))->toBeTrue()
        ->and(unreadBell($this->users['sl'])[0])->toContain('approved your request to edit 1/1/A/1-7\'s weekly report for 03OCT26 to 09OCT26. You can edit it until Sat 17OCT26')
        ->and(unreadBell($this->users['sl'])[0])->toEndWith('Go ahead.')
        ->and(AuditLogEntry::query()->where('action', DecideReportEditHandler::APPROVED)->sole()->actor_id)->toBe($this->users['pl']->id);

    // Edit and send again, as before the deadline.
    app(ReopenWeeklyReportHandler::class)->handle($this->users['sl'], new ReopenWeeklyReport($this->section->id, $this->week, 'The real report'));
    confirmPracticeHeld($this->section, $this->week);
    $again = app(SendWeeklyReportHandler::class)->handle($this->users['sl'], new SendWeeklyReport($this->section->id, $this->week, 'The real report', 'Rocket range.'));

    expect($again->alreadySent)->toBeFalse()
        ->and(WeeklyReport::query()->where('unit_id', $this->section->id)->sole()->notes)->toBe('Rocket range.');

    // Past the unlock it locks again, and a new request can be made.
    Carbon::setTestNow($until->addMinute());
    expect(fn () => app(ReopenWeeklyReportHandler::class)->handle($this->users['sl'], new ReopenWeeklyReport($this->section->id, $this->week, 'Once more')))
        ->toThrow(WeeklyReportLockedException::class);
    expect(askEdit($this->users['sl'], RequestReportEdit::weekly($this->section->id, $this->week, 'Once more'))->status)->toBe(ReportEditRequest::PENDING);
});

it('unlocks until the same Saturday when approved on a Saturday before 2359Z', function () {
    $request = askEdit($this->users['sl'], RequestReportEdit::weekly($this->section->id, $this->week, 'Wrong week'));
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-17 20:00:00', 'UTC'));

    expect(decideEdit($this->users['pl'], $request, true)->unlocked_until->toIso8601String())->toBe('2026-10-17T23:59:00+00:00');
});

it('lets anyone higher or an Admin decide too, and a denial leaves it locked', function () {
    $request = askEdit($this->users['sl'], RequestReportEdit::weekly($this->section->id, $this->week, 'Wrong week'));
    $denied = decideEdit($this->users['co'], $request, false, 'Send last week first.');

    expect($denied->status)->toBe(ReportEditRequest::DENIED)
        ->and($denied->unlocked_until)->toBeNull()
        ->and(WeeklyReport::query()->where('unit_id', $this->section->id)->sole()->unlocked_until)->toBeNull()
        ->and(unreadBell($this->users['sl'])[0])->toContain('denied your request')
        ->and(AuditLogEntry::query()->where('action', DecideReportEditHandler::DENIED)->sole()->reason)->toBe('Send last week first.')
        ->and(fn () => decideEdit($this->users['admin'], $request, true))->toThrow(ReportEditRefusedException::class, 'already decided');

    $second = askEdit($this->users['sl'], RequestReportEdit::weekly($this->section->id, $this->week, 'Please'));
    expect(decideEdit($this->users['admin'], $second, true)->status)->toBe(ReportEditRequest::APPROVED);
});

it('tells the next unit up when the one above has no leader with an account', function () {
    $units = app(UnitRepository::class);
    $platoon = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->tree['units']['company']->id);
    $section = $units->create(UnitType::Section, '1st Section', '1/2/A/1-7', $platoon->id);
    $leader = User::factory()->commanding($section, ['primary_section_id' => $section->id])->create();
    Trooper::factory()->create(['primary_section_id' => $section->id]);

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC'));
    confirmPracticeHeld($section, $this->week);
    app(SendWeeklyReportHandler::class)->handle($leader, new SendWeeklyReport($section->id, $this->week, 'Weekly report', null));
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'));

    askEdit($leader, RequestReportEdit::weekly($section->id, $this->week, 'Wrong week'));

    expect(unreadBell($this->users['co']))->toHaveCount(1)
        ->and(unreadBell($this->users['pl']))->toBe([]);
});

it('takes a staff report to the leaders above the HQ, never the HQ itself, and lets it be sent again once approved', function () {
    $psg = $this->tree['troopers']['psg'];
    $request = askEdit($this->users['psg'], RequestReportEdit::staff($psg->id, $this->week, 'Forgot the FTX'));

    expect($request->unit_id)->toBe($this->tree['units']['platoon']->id)
        ->and(unreadBell($this->users['co']))->toHaveCount(1)
        ->and(unreadBell($this->users['co'])[0])->toContain("asks to edit {$psg->display_name}'s staff report for 03OCT26 to 09OCT26")
        ->and(unreadBell($this->users['pl']))->toBe([])
        ->and(fn () => decideEdit($this->users['pl'], $request, true))->toThrow(ReportEditForbiddenException::class);

    $resend = fn () => app(SendStaffReportHandler::class)->handle($this->users['psg'], new SendStaffReport($psg->id, $this->week, false, [], notes: 'With the FTX.', reason: 'Forgot the FTX', presenceType: 'none'));
    expect($resend)->toThrow(StaffReportRefusedException::class, 'Request an edit');

    decideEdit($this->users['co'], $request, true);
    $resend();

    expect(StaffReport::query()->sole()->notes)->toBe('With the FTX.')
        ->and(unreadBell($this->users['psg'])[0])->toContain('approved your request to edit your staff report for 03OCT26 to 09OCT26');
});

it('shows the request on the report page: ask for its leader, decide for the one above', function () {
    $url = "/reports/{$this->section->id}/2026-10-03";

    $this->actingAs($this->users['sl'])->get($url)->assertInertia(fn (AssertableInertia $p) => $p
        ->where('report.canEdit', false)
        ->where('report.editRequest.locked', true)
        ->where('report.editRequest.canRequest', true)
        ->where('report.editRequest.latest', null));

    $this->actingAs($this->users['sl'])->post("{$url}/edit-request", ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->users['sl'])->post("{$url}/edit-request", ['reason' => 'Wrong week'])->assertRedirect();
    $id = ReportEditRequest::query()->sole()->id;

    $this->actingAs($this->users['sl'])->get($url)->assertInertia(fn (AssertableInertia $p) => $p
        ->where('report.editRequest.canRequest', false)
        ->where('report.editRequest.canDecide', false)
        ->where('report.editRequest.latest.status', 'pending')
        ->where('report.editRequest.latest.reason', 'Wrong week'));
    $this->actingAs($this->users['pl'])->get($url)->assertInertia(fn (AssertableInertia $p) => $p->where('report.editRequest.canDecide', true));

    $this->actingAs($this->users['sl'])->post("/report-edit-requests/{$id}/approve")->assertForbidden();
    $this->actingAs($this->users['pl'])->post("/report-edit-requests/{$id}/approve", ['note' => 'OK'])->assertRedirect();

    $this->actingAs($this->users['sl'])->get($url)->assertInertia(fn (AssertableInertia $p) => $p
        ->where('report.canEdit', true)
        ->where('report.editRequest.locked', false)
        ->where('report.editRequest.editableUntil', '2026-10-17T23:59:00+00:00')
        ->where('report.editRequest.latest.status', 'approved'));
});

it('shows a staff report\'s request on its page', function () {
    $psg = $this->tree['troopers']['psg'];
    $url = "/staff-report/{$psg->id}/2026-10-03";

    $this->actingAs($this->users['psg'])->get($url)->assertInertia(fn (AssertableInertia $p) => $p
        ->where('canEdit', false)
        ->where('editRequest.canRequest', true));
    $this->actingAs($this->users['psg'])->post("{$url}/edit-request", ['reason' => 'Forgot the FTX'])->assertRedirect();
    $this->actingAs($this->users['co'])->get($url)->assertInertia(fn (AssertableInertia $p) => $p->where('editRequest.canDecide', true));
});

it('opens the section\'s roll call again once an approved unlock is reopened, so marks can be fixed', function () {
    $trooper = Trooper::query()->where('primary_section_id', $this->section->id)->first();

    $request = askEdit($this->users['sl'], RequestReportEdit::weekly($this->section->id, $this->week, 'Wrong marks'));
    decideEdit($this->users['pl'], $request, true);
    app(ReopenWeeklyReportHandler::class)->handle($this->users['sl'], new ReopenWeeklyReport($this->section->id, $this->week, 'Fixing the marks'));

    app(MarkAttendanceHandler::class)->handle($this->users['sl'], new MarkAttendance($this->section->id, $this->week, new AttendanceMark($trooper->id, AttendanceStatus::Present)));

    expect(Attendance::query()->where('trooper_id', $trooper->id)->value('status'))->toBe(AttendanceStatus::Present);
});

it('never lets the person who asked decide, whoever they act as or acted as when asking', function () {
    $real = app(RealActor::class);

    // Asked as themselves, then acting as someone above who could decide: refused.
    $request = askEdit($this->users['sl'], RequestReportEdit::weekly($this->section->id, $this->week, 'Wrong week'));
    $real->set($this->users['sl']);
    expect(fn () => decideEdit($this->users['pl'], $request, true))->toThrow(ReportEditForbiddenException::class);
    $real->clear();
    decideEdit($this->users['admin'], $request, false);

    // Asked while acting as the section leader: the person behind it may not decide as themselves either.
    $real->set($this->users['co']);
    $second = askEdit($this->users['sl'], RequestReportEdit::weekly($this->section->id, $this->week, 'Again'));
    $real->clear();

    expect($second->real_requested_by)->toBe($this->users['co']->id)
        ->and(fn () => decideEdit($this->users['co'], $second, true))->toThrow(ReportEditForbiddenException::class)
        ->and(decideEdit($this->users['pl'], $second, true)->status)->toBe(ReportEditRequest::APPROVED);
});

it('keeps an Admin on the unit\'s own command team from deciding its request, as anyone on that team', function () {
    $adminLeader = User::factory()->admin()->commanding($this->section)->create();
    $request = askEdit($this->users['sl'], RequestReportEdit::weekly($this->section->id, $this->week, 'Wrong week'));

    expect(fn () => decideEdit($adminLeader, $request, true))->toThrow(ReportEditForbiddenException::class)
        ->and(decideEdit($this->users['admin'], $request, true)->status)->toBe(ReportEditRequest::APPROVED);
});

it('keeps a unit an edit request names, as its reports do', function () {
    $empty = app(UnitRepository::class)->create(UnitType::Platoon, '3rd Platoon', '3/A/1-7', $this->tree['units']['company']->id);
    ReportEditRequest::query()->create(['unit_id' => $empty->id, 'week_start' => '2026-10-03', 'requested_by' => $this->users['sl']->id, 'reason' => 'Old']);

    expect(fn () => app(DeleteUnit::class)->handle($this->users['admin'], $empty))->toThrow(UnitHasDependentsException::class, 'requests to edit');
});
