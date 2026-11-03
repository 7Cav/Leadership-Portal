<?php

use App\Commands\StaffReports\SendStaffReport;
use App\Commands\StaffReports\SendStaffReportHandler;
use App\Commands\StaffReports\StaffReportForbiddenException;
use App\Commands\StaffReports\StaffReportRefusedException;
use App\Models\AuditLogEntry;
use App\Models\CrossSectionSighting;
use App\Models\StaffReport;
use App\Models\Trooper;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../StaffPractices/fixture.php';

// A sent staff report can be sent again, with a reason, until it locks at its deadline (2359Z on the Saturday after
// the week), as a unit's weekly report can be edited. The version it replaces is kept.

beforeEach(function () {
    $this->tree = staffPracticeTree();
    $this->week = staffPracticeWeek();
    // The Saturday after the fixture's week: it has ended and its report is due tonight at 2359Z.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    $this->due = CarbonImmutable::parse('2026-10-10 23:59:00', 'UTC');
    $this->host = $this->tree['units']['host'];
    $this->psg = $this->tree['troopers']['psg'];
    $this->user = $this->tree['users']['psg'];
});

afterEach(fn () => CarbonImmutable::setTestNow());

function editStaff(User $actor, Trooper $trooper, bool $attended, array $sectionIds, ?string $reason = null, ?string $notes = null): StaffReport
{
    return app(SendStaffReportHandler::class)->handle($actor, new SendStaffReport(
        $trooper->id, staffPracticeWeek(), $attended, $sectionIds, notes: $notes, reason: $reason,
    ));
}

function editStaffRefusal(Closure $write): string
{
    try {
        $write();
    } catch (StaffReportRefusedException $e) {
        return $e->reason;
    }

    throw new RuntimeException('Expected the staff report to be refused.');
}

it('sends a sent report again with a reason, keeping the version it replaces and setting the entries again', function () {
    editStaff($this->user, $this->psg, true, [$this->host->id], notes: 'First.');

    $edited = editStaff($this->user, $this->psg, false, [], 'Picked the wrong week', 'Second.');

    expect($edited->attended)->toBeFalse()
        ->and($edited->notes)->toBe('Second.')
        ->and($edited->practice_type)->toBeNull()
        ->and($edited->revisions)->toHaveCount(1)
        ->and($edited->revisions[0]['attended'])->toBeTrue()
        ->and($edited->revisions[0]['notes'])->toBe('First.')
        ->and(StaffReport::query()->count())->toBe(1)
        // The entry for the practice no longer reported is taken off.
        ->and(CrossSectionSighting::query()->count())->toBe(0);

    expect(AuditLogEntry::query()->where('action', 'staff_report.edited')->sole()->reason)->toBe('Picked the wrong week');
});

it('requires a reason to edit, and changes nothing without one', function () {
    editStaff($this->user, $this->psg, true, [$this->host->id]);

    expect(editStaffRefusal(fn () => editStaff($this->user, $this->psg, false, [], '  ')))->toBe('reason_required')
        ->and(StaffReport::query()->sole()->attended)->toBeTrue()
        ->and(AuditLogEntry::query()->where('action', 'staff_report.edited')->count())->toBe(0);
});

it('can be edited up to the deadline minute and not after', function () {
    editStaff($this->user, $this->psg, true, [$this->host->id]);

    CarbonImmutable::setTestNow($this->due);
    expect(editStaff($this->user, $this->psg, false, [], 'Fix')->attended)->toBeFalse();

    CarbonImmutable::setTestNow($this->due->addSecond());
    expect(editStaffRefusal(fn () => editStaff($this->user, $this->psg, true, [$this->host->id], 'Too late')))->toBe('locked')
        ->and(StaffReport::query()->sole()->attended)->toBeFalse();
});

it('lets only the staff member edit it', function () {
    editStaff($this->user, $this->psg, true, [$this->host->id]);

    expect(fn () => editStaff($this->tree['users']['pl'], $this->psg, false, [], 'Not mine'))->toThrow(StaffReportForbiddenException::class);
});

it('offers Edit report on the staff member\'s own page only until the deadline', function () {
    editStaff($this->user, $this->psg, true, [$this->host->id]);
    $url = "/staff-report/{$this->psg->id}/2026-10-03";

    $this->actingAs($this->user)->get($url)->assertInertia(fn (AssertableInertia $p) => $p->where('canEdit', true)->where('canSend', false));
    $this->actingAs($this->user)->post($url, ['attended' => false, 'section_ids' => []])->assertSessionHasErrors('reason');
    $this->actingAs($this->user)->post($url, ['attended' => false, 'section_ids' => [], 'reason' => 'Wrong answer'])->assertRedirect($url);
    expect(StaffReport::query()->sole()->attended)->toBeFalse();

    CarbonImmutable::setTestNow($this->due->addMinute());
    $this->actingAs($this->user)->get($url)->assertInertia(fn (AssertableInertia $p) => $p->where('canEdit', false));
});

it('shows the versions an edit replaced, with when and why it was sent again, to whoever reads the report', function () {
    editStaff($this->user, $this->psg, true, [$this->host->id], notes: 'First.');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-10 18:00:00', 'UTC'));
    editStaff($this->user, $this->psg, false, [], 'Picked the wrong week', 'Second.');
    $url = route('staff-report.show', ['trooper' => $this->psg->id, 'week' => '2026-10-03']);

    foreach ([$this->user, $this->tree['users']['co']] as $viewer) {
        $this->actingAs($viewer)->get($url)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.notes', 'Second.')
            ->has('report.revisions', 1)
            ->where('report.revisions.0.sentAt', '2026-10-10T12:00:00+00:00')
            ->where('report.revisions.0.attended', true)
            ->where('report.revisions.0.notes', 'First.')
            ->where('report.revisions.0.practices.0.sectionId', $this->host->id)
            ->where('report.revisions.0.sentBy', $this->psg->display_name)
            ->where('report.revisions.0.replaced.at', '2026-10-10T18:00:00+00:00')
            ->where('report.revisions.0.replaced.by', $this->psg->display_name)
            ->where('report.revisions.0.replaced.reason', 'Picked the wrong week'));
    }
});
