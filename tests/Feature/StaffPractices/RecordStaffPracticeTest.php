<?php

use App\Commands\StaffPractices\RecordStaffPractice;
use App\Commands\StaffPractices\RecordStaffPracticeHandler;
use App\Commands\StaffPractices\RemoveStaffPractice;
use App\Commands\StaffPractices\RemoveStaffPracticeHandler;
use App\Commands\StaffPractices\StaffPracticeForbiddenException;
use App\Commands\StaffPractices\StaffPracticeRefusedException;
use App\Http\Controllers\TrooperDetailController;
use App\Models\AuditLogEntry;
use App\Models\CrossSectionSighting;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\SectionPracticeRepository;
use App\Support\Audit\ActingFor;
use Carbon\CarbonImmutable;

require_once __DIR__.'/fixture.php';

// #448: a staff member (HQ staff or a staff department) attended a section's practice. They, their staff leader or an
// Admin enter it; it is audited, one per practice, never before the floor or in the future, and locked once their HQ's
// report for the week is sent.

beforeEach(function () {
    $this->tree = staffPracticeTree();
    $this->week = staffPracticeWeek();
});

function recordStaff(User $actor, Trooper $trooper, int $sectionId, ?CarbonImmutable $week = null): CrossSectionSighting
{
    return app(RecordStaffPracticeHandler::class)->handle($actor, new RecordStaffPractice($trooper->id, $sectionId, $week ?? staffPracticeWeek()));
}

function removeStaff(User $actor, Trooper $trooper, int $sectionId, ?CarbonImmutable $week = null): void
{
    app(RemoveStaffPracticeHandler::class)->handle($actor, new RemoveStaffPractice($trooper->id, $sectionId, $week ?? staffPracticeWeek()));
}

function refusedStaff(Closure $write): string
{
    try {
        $write();
    } catch (StaffPracticeRefusedException $e) {
        return $e->reason;
    }

    throw new RuntimeException('Expected the entry to be refused.');
}

it('lets each allowed person enter a staff member\'s practice, naming who entered it', function (string $actor, string $trooper) {
    $user = $this->tree['users'][$actor];
    $staff = $this->tree['troopers'][$trooper];

    $row = recordStaff($user, $staff, $this->tree['units']['host']->id);

    $practice = SectionPractice::query()->where('section_id', $this->tree['units']['host']->id)->sole();
    expect($row->source)->toBe(CrossSectionSighting::SOURCE_STAFF)
        ->and($row->trooper_id)->toBe($staff->id)
        ->and($row->section_practice_id)->toBe($practice->id)
        ->and($row->week_start->toDateString())->toBe('2026-10-03')
        ->and($row->recorded_by)->toBe($user->id)
        ->and($row->seen_from)->toBeNull();
})->with([
    'the HQ staff member themselves' => ['psg', 'psg'],
    'the staff department member themselves' => ['s6Member', 's6Member'],
    'the platoon leader over their PSG' => ['pl', 'psg'],
    'the company commander above' => ['co', 'psg'],
    'the department lead over their member' => ['s6Lead', 's6Member'],
    'an Admin' => ['admin', 's6Member'],
]);

it('refuses anyone else, writing nothing', function (string $actor, string $trooper) {
    expect(fn () => recordStaff($this->tree['users'][$actor], $this->tree['troopers'][$trooper], $this->tree['units']['host']->id))
        ->toThrow(StaffPracticeForbiddenException::class);

    expect(CrossSectionSighting::query()->count())->toBe(0)
        ->and(SectionPractice::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->count())->toBe(0);
})->with([
    'a section leader below the PSG' => ['sl', 'psg'],
    'a staff grant on the company' => ['grant', 'psg'],
    'another staff member' => ['psg', 's6Member'],
    'a platoon leader outside the department' => ['pl', 's6Member'],
]);

it('refuses a trooper who is not staff: a section trooper, ELOA, a reservist or a new recruit', function (string $trooper) {
    expect(refusedStaff(fn () => recordStaff($this->tree['users']['admin'], $this->tree['troopers'][$trooper], $this->tree['units']['other']->id)))
        ->toBe('not_staff');
    expect(CrossSectionSighting::query()->count())->toBe(0);
})->with(['rifleman', 'eloa', 'reservist', 'recruit']);

it('refuses a staff member who no longer serves', function () {
    $this->tree['troopers']['psg']->forceFill(['status' => TrooperStatus::Inactive])->save();

    expect(refusedStaff(fn () => recordStaff($this->tree['users']['pl'], $this->tree['troopers']['psg'], $this->tree['units']['host']->id)))
        ->toBe('not_serving');
});

it('refuses a unit that is not a section', function () {
    expect(refusedStaff(fn () => recordStaff($this->tree['users']['pl'], $this->tree['troopers']['psg'], $this->tree['units']['platoon']->id)))
        ->toBe('not_a_section');
});

it('refuses a second entry for the same section and week, but takes another section the same week', function () {
    recordStaff($this->tree['users']['psg'], $this->tree['troopers']['psg'], $this->tree['units']['host']->id);

    expect(refusedStaff(fn () => recordStaff($this->tree['users']['pl'], $this->tree['troopers']['psg'], $this->tree['units']['host']->id)))
        ->toBe('already_recorded');

    recordStaff($this->tree['users']['pl'], $this->tree['troopers']['psg'], $this->tree['units']['other']->id);
    expect(CrossSectionSighting::query()->count())->toBe(2)
        ->and(AuditLogEntry::query()->where('action', 'staff_practice.recorded')->count())->toBe(2);
});

it('refuses a week before the floor, one older than the form offers and a future week', function (string $week, string $reason) {
    expect(refusedStaff(fn () => recordStaff($this->tree['users']['pl'], $this->tree['troopers']['psg'], $this->tree['units']['host']->id, CarbonImmutable::parse($week, 'UTC'))))
        ->toBe($reason);
    expect(CrossSectionSighting::query()->count())->toBe(0)
        ->and(SectionPractice::query()->count())->toBe(0);
})->with([
    'before the floor' => ['2026-05-02', 'before_floor'],
    // The floor is 20 weeks back, the form offers this week and the 11 before it (audit 2, L3).
    'twelve weeks back, after the floor' => ['2026-07-11', 'outside_window'],
    'next week' => ['2026-10-10', 'future'],
]);

it('takes any week of the form\'s window, snapped to its Cav week, the oldest it offers included', function () {
    $row = recordStaff($this->tree['users']['pl'], $this->tree['troopers']['psg'], $this->tree['units']['host']->id, CarbonImmutable::parse('2026-09-30', 'UTC'));
    $oldest = recordStaff($this->tree['users']['pl'], $this->tree['troopers']['psg'], $this->tree['units']['other']->id, CarbonImmutable::parse('2026-07-18', 'UTC'));

    expect($row->week_start->toDateString())->toBe('2026-09-26')
        ->and($oldest->week_start->toDateString())->toBe('2026-07-18')
        ->and(RecordStaffPracticeHandler::WEEKS)->toBe(TrooperDetailController::STAFF_PRACTICE_WEEKS);
});

it('locks a week whose report the staff member\'s HQ has sent, as a section\'s is', function () {
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->tree['units']['platoon']->id, 'week_start' => $this->week]);

    expect(refusedStaff(fn () => recordStaff($this->tree['users']['pl'], $this->tree['troopers']['psg'], $this->tree['units']['host']->id)))
        ->toBe('locked');
    expect(CrossSectionSighting::query()->count())->toBe(0);
});

it('leaves a week open while the HQ report is a draft, and never locks on the host section\'s report', function () {
    WeeklyReport::factory()->create(['unit_id' => $this->tree['units']['platoon']->id, 'week_start' => $this->week]);
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->tree['units']['host']->id, 'week_start' => $this->week]);

    recordStaff($this->tree['users']['pl'], $this->tree['troopers']['psg'], $this->tree['units']['host']->id);

    expect(CrossSectionSighting::query()->count())->toBe(1);
});

it('audits an entry against the staff member, and the Admin behind one made while acting as them', function () {
    $admin = $this->tree['users']['admin'];
    $psg = $this->tree['users']['psg'];
    app(ActingFor::class)->set($admin->id, $psg->id);

    $row = recordStaff($psg, $this->tree['troopers']['psg'], $this->tree['units']['host']->id);

    $entry = AuditLogEntry::query()->where('action', 'staff_practice.recorded')->sole();
    expect($row->recorded_by)->toBe($psg->id)
        ->and($row->recorded_by_admin)->toBe($admin->id)
        ->and($entry->actor_id)->toBe($admin->id)
        ->and($entry->on_behalf_of_id)->toBe($psg->id)
        ->and($entry->target_type)->toBe('Trooper')
        ->and((int) $entry->target_id)->toBe($this->tree['troopers']['psg']->id)
        ->and($entry->content_digest)->not->toBeNull();
});

it('lets the same people remove an entry, audited, and nobody else', function () {
    recordStaff($this->tree['users']['psg'], $this->tree['troopers']['psg'], $this->tree['units']['host']->id);

    expect(fn () => removeStaff($this->tree['users']['sl'], $this->tree['troopers']['psg'], $this->tree['units']['host']->id))
        ->toThrow(StaffPracticeForbiddenException::class);
    expect(fn () => removeStaff($this->tree['users']['grant'], $this->tree['troopers']['psg'], $this->tree['units']['host']->id))
        ->toThrow(StaffPracticeForbiddenException::class);

    removeStaff($this->tree['users']['pl'], $this->tree['troopers']['psg'], $this->tree['units']['host']->id);

    $entry = AuditLogEntry::query()->where('action', 'staff_practice.removed')->sole();
    expect(CrossSectionSighting::query()->count())->toBe(0)
        ->and($entry->actor_id)->toBe($this->tree['users']['pl']->id)
        ->and($entry->target_type)->toBe('Trooper')
        ->and((int) $entry->target_id)->toBe($this->tree['troopers']['psg']->id);
});

it('lets a staff member take back their own self-report', function () {
    recordStaff($this->tree['users']['s6Member'], $this->tree['troopers']['s6Member'], $this->tree['units']['host']->id);

    removeStaff($this->tree['users']['s6Member'], $this->tree['troopers']['s6Member'], $this->tree['units']['host']->id);

    expect(CrossSectionSighting::query()->count())->toBe(0);
});

it('never removes a guest row or anything missing, and refuses a removal in a locked week', function () {
    $practice = app(SectionPracticeRepository::class)->findOrCreate($this->tree['units']['host']->id, $this->week);
    app(CrossSectionSightingRepository::class)->recordManual($practice, $this->tree['troopers']['psg']->id, $this->tree['users']['sl']->id);

    expect(refusedStaff(fn () => removeStaff($this->tree['users']['admin'], $this->tree['troopers']['psg'], $this->tree['units']['host']->id)))->toBe('not_recorded')
        ->and(refusedStaff(fn () => removeStaff($this->tree['users']['admin'], $this->tree['troopers']['psg'], $this->tree['units']['other']->id)))->toBe('not_recorded')
        ->and(CrossSectionSighting::query()->sole()->source)->toBe('manual');

    recordStaff($this->tree['users']['pl'], $this->tree['troopers']['psg'], $this->tree['units']['other']->id);
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->tree['units']['platoon']->id, 'week_start' => $this->week]);

    expect(refusedStaff(fn () => removeStaff($this->tree['users']['pl'], $this->tree['troopers']['psg'], $this->tree['units']['other']->id)))->toBe('locked')
        ->and(CrossSectionSighting::query()->count())->toBe(2)
        ->and(AuditLogEntry::query()->where('action', 'staff_practice.removed')->count())->toBe(0);
});
