<?php

use App\Commands\Guests\GuestAttendanceRefusedException;
use App\Commands\Guests\RecordGuestAttendance;
use App\Commands\Guests\RecordGuestAttendanceHandler;
use App\Commands\StaffPractices\RecordStaffPractice;
use App\Commands\StaffPractices\RecordStaffPracticeHandler;
use App\Commands\StaffPractices\StaffPracticeRefusedException;
use App\Models\CrossSectionSighting;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\SectionPracticeRepository;

require_once __DIR__.'/fixture.php';

// Audit L2: a staff entry and a guest entry share one row per trooper and practice. After a trooper moves between a
// section and an HQ, the row the other kind left is named in the refusal, instead of "already recorded" or "already
// listed" while the list being added to shows nothing.

beforeEach(function () {
    $this->tree = staffPracticeTree();
    $this->week = staffPracticeWeek();
});

function staffRefusal(Closure $write): StaffPracticeRefusedException
{
    try {
        $write();
    } catch (StaffPracticeRefusedException $e) {
        return $e;
    }

    throw new RuntimeException('Expected the staff entry to be refused.');
}

it('says a trooper who moved to the staff is already a guest at that practice, and how they got there', function (bool $captured, string $how) {
    $trooper = $this->tree['troopers']['rifleman'];
    $practice = app(SectionPracticeRepository::class)->findOrCreate($this->tree['units']['other']->id, $this->week);
    $captured
        ? app(CrossSectionSightingRepository::class)->record($practice, [$trooper->id], now(), now())
        : app(CrossSectionSightingRepository::class)->recordManual($practice, $trooper->id, $this->tree['users']['sl']->id);
    $trooper->forceFill(['primary_section_id' => $this->tree['units']['platoon']->id])->save();

    $refusal = staffRefusal(fn () => app(RecordStaffPracticeHandler::class)->handle($this->tree['users']['admin'], new RecordStaffPractice($trooper->id, $this->tree['units']['other']->id, $this->week)));

    expect($refusal->reason)->toBe('already_recorded')
        ->and($refusal->getMessage())->toBe("{$trooper->display_name} is already listed as a guest at that practice, {$how}. That entry is on the section's rollcall, so there is nothing to add here.")
        ->and(CrossSectionSighting::query()->sole()->source)->toBe($captured ? 'capture' : 'manual');
})->with([
    'added by hand' => [false, "added by the section's leader"],
    'seen by the game server' => [true, 'seen by the game server'],
]);

it('still says a second staff entry is already recorded', function () {
    $handler = app(RecordStaffPracticeHandler::class);
    $handler->handle($this->tree['users']['admin'], new RecordStaffPractice($this->tree['troopers']['psg']->id, $this->tree['units']['host']->id, $this->week));

    $refusal = staffRefusal(fn () => $handler->handle($this->tree['users']['admin'], new RecordStaffPractice($this->tree['troopers']['psg']->id, $this->tree['units']['host']->id, $this->week)));

    expect($refusal->getMessage())->toBe("{$this->tree['troopers']['psg']->display_name} is already recorded at that practice.");
});

it('says a trooper who moved into a section has a staff entry for that practice, instead of calling them listed', function () {
    $psg = $this->tree['troopers']['psg'];
    app(RecordStaffPracticeHandler::class)->handle($this->tree['users']['admin'], new RecordStaffPractice($psg->id, $this->tree['units']['host']->id, $this->week));
    $psg->forceFill(['primary_section_id' => $this->tree['units']['other']->id])->save();

    try {
        app(RecordGuestAttendanceHandler::class)->handle($this->tree['users']['sl'], new RecordGuestAttendance($this->tree['units']['host']->id, $this->week, $psg->id));
        $refusal = null;
    } catch (GuestAttendanceRefusedException $e) {
        $refusal = $e;
    }

    expect($refusal?->reason)->toBe('already_listed')
        ->and($refusal?->getMessage())->toBe("{$psg->display_name} already has a staff practice entry for this practice, on their milpac page, so they cannot also be listed as a guest.")
        ->and(CrossSectionSighting::query()->sole()->source)->toBe('staff');
});
