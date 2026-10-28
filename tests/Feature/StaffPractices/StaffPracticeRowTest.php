<?php

use App\Commands\StaffPractices\RecordStaffPractice;
use App\Commands\StaffPractices\RecordStaffPracticeHandler;
use App\Commands\StaffPractices\RemoveStaffPractice;
use App\Commands\StaffPractices\RemoveStaffPracticeHandler;
use App\Models\Attendance;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\CrossSectionSighting;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\User;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\DemoHistoryRepository;
use App\Repositories\SectionPracticeRepository;

require_once __DIR__.'/fixture.php';

// Audit 2 (L1): a staff entry creates the host section's practice row when the section has none that week. Taking the
// last entry back removes that row again when nothing else hangs off it, so it never blocks deleting the section; a
// staff entry keeps a generated demo practice demo, and the demo purge keeps the entry while it clears the marks.

beforeEach(function () {
    $this->tree = staffPracticeTree();
    $this->week = staffPracticeWeek();
});

function rowStaffEntry(User $actor, Trooper $trooper, int $sectionId): void
{
    app(RecordStaffPracticeHandler::class)->handle($actor, new RecordStaffPractice($trooper->id, $sectionId, staffPracticeWeek()));
}

function rowStaffRemoval(User $actor, Trooper $trooper, int $sectionId): void
{
    app(RemoveStaffPracticeHandler::class)->handle($actor, new RemoveStaffPractice($trooper->id, $sectionId, staffPracticeWeek()));
}

it('removes the practice row a staff entry created once its last entry is taken back, so the section can be deleted', function () {
    $other = $this->tree['units']['other'];
    rowStaffEntry($this->tree['users']['admin'], $this->tree['troopers']['psg'], $other->id);
    rowStaffEntry($this->tree['users']['admin'], $this->tree['troopers']['s6Member'], $other->id);

    // One entry left: the practice stays.
    rowStaffRemoval($this->tree['users']['admin'], $this->tree['troopers']['psg'], $other->id);
    expect(SectionPractice::query()->where('section_id', $other->id)->count())->toBe(1);

    rowStaffRemoval($this->tree['users']['admin'], $this->tree['troopers']['s6Member'], $other->id);

    expect(SectionPractice::query()->where('section_id', $other->id)->exists())->toBeFalse()
        ->and(app(SectionPracticeRepository::class)->hasPracticesFor($other))->toBeFalse()
        ->and(AuditLogEntry::query()->where('action', 'staff_practice.removed')->count())->toBe(2);
});

it('keeps the practice when anything else hangs off it: a guest, a mark or a posted rollcall', function (string $what) {
    $host = $this->tree['units']['host'];
    $practices = app(SectionPracticeRepository::class);
    $practice = $practices->findOrCreate($host->id, $this->week);

    match ($what) {
        'guest' => app(CrossSectionSightingRepository::class)->recordManual($practice, $this->tree['troopers']['eloa']->id, $this->tree['users']['sl']->id),
        'mark' => Attendance::query()->forceCreate([
            'trooper_id' => $this->tree['troopers']['rifleman']->id,
            'section_practice_id' => $practice->id,
            'rollcall' => RollcallSignature::WillAttend,
        ]),
        'posted rollcall' => $practices->markRollcallPosted($practice, $this->tree['users']['sl']->id),
    };

    rowStaffEntry($this->tree['users']['pl'], $this->tree['troopers']['psg'], $host->id);
    rowStaffRemoval($this->tree['users']['pl'], $this->tree['troopers']['psg'], $host->id);

    expect(SectionPractice::query()->whereKey($practice->id)->exists())->toBeTrue();
})->with(['guest', 'mark', 'posted rollcall']);

it('keeps a demo practice demo, and the demo purge clears its marks but keeps the staff entry', function () {
    $host = $this->tree['units']['host'];
    $demo = app(DemoHistoryRepository::class);
    $practice = $demo->createPractices([$host->id], $this->week)->get($host->id);
    $demo->insertAttendance([[
        'trooper_id' => $this->tree['troopers']['rifleman']->id,
        'section_practice_id' => $practice->id,
        'status' => AttendanceStatus::Present,
        'rollcall' => RollcallSignature::WillAttend,
    ]]);

    rowStaffEntry($this->tree['users']['pl'], $this->tree['troopers']['psg'], $host->id);

    expect($practice->fresh()->is_demo)->toBeTrue()
        // A real record hangs off the week now, so generating demo history leaves it alone.
        ->and($demo->weeksWithRealHistory([$host->id], [$host->id], [$this->week]))->toHaveCount(1);

    $demo->purge([$host->id], [$host->id]);

    expect(Attendance::query()->count())->toBe(0)
        ->and(CrossSectionSighting::query()->sole()->trooper_id)->toBe($this->tree['troopers']['psg']->id)
        ->and($practice->fresh()->is_demo)->toBeFalse();
});
