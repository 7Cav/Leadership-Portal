<?php

use App\Commands\Attendance\MakeupWithdrawal;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Commands\Guests\RecordGuestAttendance;
use App\Commands\Guests\RecordGuestAttendanceHandler;
use App\Commands\Guests\RemoveGuestAttendance;
use App\Commands\Guests\RemoveGuestAttendanceHandler;
use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\CrossSectionSighting;
use App\Models\Loa;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// A hand-added guest (decided 2026-10-23) puts the same automatic makeup on the guest's own rollcall as a capture
// sighting, through MarkAttendanceHandler::preMarkMakeups() and all its guards, on the host leader's word. Undoing it
// takes that makeup back only while it is still the automatic makeup and nothing else supports it.

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC'));
    $this->week = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
    $units = app(UnitRepository::class);
    $this->host = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', null);
    $this->other = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', null);
    $this->home = $units->create(UnitType::Section, '3rd Section', '3/2/B/1-7', null);
    $this->leader = User::factory()->commanding($this->host)->create();
    $this->guest = Trooper::factory()->create(['status' => TrooperStatus::Combat]);
    $this->guest->forceFill(['primary_section_id' => $this->home->id])->save();
});

function recordGuest(object $t): string
{
    return app(RecordGuestAttendanceHandler::class)->handle($t->leader, new RecordGuestAttendance($t->host->id, $t->week, $t->guest->id))->makeup;
}

function withdrawGuest(object $t): MakeupWithdrawal
{
    return app(RemoveGuestAttendanceHandler::class)->handle($t->leader, new RemoveGuestAttendance($t->host->id, $t->week, $t->guest->id));
}

function homeMark(object $t): ?Attendance
{
    $practice = SectionPractice::query()->where('section_id', $t->home->id)->where('week_start', $t->week)->first();

    return $practice === null ? null : Attendance::query()->where('section_practice_id', $practice->id)->where('trooper_id', $t->guest->id)->first();
}

function setHomeMark(object $t, ?AttendanceStatus $status, ?AttendanceSource $source): SectionPractice
{
    $practice = app(SectionPracticeRepository::class)->findOrCreate($t->home->id, $t->week);
    Attendance::factory()->create(['trooper_id' => $t->guest->id, 'section_practice_id' => $practice->id, 'status' => $status, 'source' => $source]);

    return $practice;
}

it('puts an automatic makeup on the guest\'s own rollcall, audited with the host leader as the actor', function () {
    expect(recordGuest($this))->toBe('marked');

    $mark = homeMark($this);
    $entry = AuditLogEntry::query()->where('action', 'attendance.auto_marked')->sole();
    expect($mark->status)->toBe(AttendanceStatus::Makeup)
        ->and($mark->source)->toBe(AttendanceSource::Auto)
        ->and($entry->actor_id)->toBe($this->leader->id)
        ->and((int) $entry->target_id)->toBe($mark->section_practice_id);
});

it('never overwrites an attended, loa or manual mark on the guest\'s own rollcall', function (AttendanceStatus $status, AttendanceSource $source) {
    setHomeMark($this, $status, $source);

    expect(recordGuest($this))->toBe('kept');

    $mark = homeMark($this);
    expect($mark->status)->toBe($status)->and($mark->source)->toBe($source)
        ->and(CrossSectionSighting::query()->count())->toBe(1);
})->with([
    'auto present' => [AttendanceStatus::Present, AttendanceSource::Auto],
    'manual present' => [AttendanceStatus::Present, AttendanceSource::Manual],
    'manual excused' => [AttendanceStatus::Excused, AttendanceSource::Manual],
    'loa' => [AttendanceStatus::Loa, AttendanceSource::Manual],
    'manual missed' => [AttendanceStatus::Missed, AttendanceSource::Manual],
]);

it('turns an automatic miss into the makeup', function () {
    setHomeMark($this, AttendanceStatus::Missed, AttendanceSource::Auto);

    expect(recordGuest($this))->toBe('marked')->and(homeMark($this)->status)->toBe(AttendanceStatus::Makeup);
});

it('writes nothing into a locked or confirmed own week, but still records the guest', function (string $lock) {
    if ($lock === 'sent') {
        WeeklyReport::factory()->sent()->create(['unit_id' => $this->home->id, 'week_start' => $this->week]);
    } else {
        app(SectionPracticeRepository::class)->confirm(app(SectionPracticeRepository::class)->findOrCreate($this->home->id, $this->week), $this->leader->id);
    }

    expect(recordGuest($this))->toBe('locked')
        ->and(homeMark($this))->toBeNull()
        ->and(CrossSectionSighting::query()->sole()->source)->toBe('manual');
})->with(['sent report' => ['sent'], 'confirmed practice' => ['confirmed']]);

it('skips a guest whose leave covers the week', function () {
    Loa::factory()->create(['trooper_id' => $this->guest->id, 'starts_at' => $this->week->subDays(2), 'ends_at' => $this->week->addDays(9)]);

    expect(recordGuest($this))->toBe('kept')->and(homeMark($this))->toBeNull();
});

it('takes the makeup back with the entry when nothing else supports it, audited with the same actor', function () {
    recordGuest($this);

    expect(withdrawGuest($this))->toBe(MakeupWithdrawal::Withdrawn);

    $mark = homeMark($this);
    $entry = AuditLogEntry::query()->where('action', 'attendance.auto_unmarked')->sole();
    expect($mark->status)->toBeNull()
        ->and($mark->source)->toBeNull()
        ->and($entry->actor_id)->toBe($this->leader->id)
        ->and((int) $entry->target_id)->toBe($mark->section_practice_id)
        ->and(CrossSectionSighting::query()->count())->toBe(0);
});

it('takes the makeup back from the section it was written to when the guest has moved section since', function () {
    recordGuest($this);
    $this->guest->forceFill(['primary_section_id' => $this->other->id])->save();

    expect(withdrawGuest($this))->toBe(MakeupWithdrawal::Withdrawn)
        ->and(homeMark($this)->status)->toBeNull()
        ->and(homeMark($this)->source)->toBeNull()
        ->and(AuditLogEntry::query()->where('action', 'attendance.auto_unmarked')->sole()->target_id)->toEqual(homeMark($this)->section_practice_id);
});

it('leaves the makeup on the old section when that week is locked, after the guest moved', function () {
    recordGuest($this);
    $this->guest->forceFill(['primary_section_id' => $this->other->id])->save();
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->home->id, 'week_start' => $this->week]);

    expect(withdrawGuest($this))->toBe(MakeupWithdrawal::Locked)
        ->and(homeMark($this)->status)->toBe(AttendanceStatus::Makeup);
});

it('retries the makeup when a guest whose makeup failed after the entry was saved is added again', function () {
    $failing = Mockery::mock(app(MarkAttendanceHandler::class))->makePartial();
    $failing->shouldReceive('preMarkMakeups')->once()->andThrow(new RuntimeException('connection lost'));
    app()->instance(MarkAttendanceHandler::class, $failing);

    expect(fn () => recordGuest($this))->toThrow(RuntimeException::class);
    expect(CrossSectionSighting::query()->sole()->source)->toBe('manual')->and(homeMark($this))->toBeNull();

    app()->forgetInstance(MarkAttendanceHandler::class);

    expect(recordGuest($this))->toBe('marked')
        ->and(homeMark($this)->status)->toBe(AttendanceStatus::Makeup)
        ->and(CrossSectionSighting::query()->count())->toBe(1)
        ->and(AuditLogEntry::query()->where('action', 'guest.recorded')->count())->toBe(1);
});

it('retries a makeup that was locked out once the own week opens, without listing the guest twice', function () {
    $report = WeeklyReport::factory()->sent()->create(['unit_id' => $this->home->id, 'week_start' => $this->week]);
    expect(recordGuest($this))->toBe('locked');

    $report->delete();

    expect(recordGuest($this))->toBe('marked')->and(CrossSectionSighting::query()->count())->toBe(1);
});

it('keeps the makeup when another sighting that week still supports it', function (string $source) {
    recordGuest($this);
    $elsewhere = app(SectionPracticeRepository::class)->findOrCreate($this->other->id, $this->week);
    CrossSectionSighting::query()->create([
        'trooper_id' => $this->guest->id, 'section_practice_id' => $elsewhere->id, 'week_start' => $this->week, 'source' => $source,
        'seen_from' => $source === 'capture' ? '2026-10-07 18:00:00' : null, 'seen_until' => $source === 'capture' ? '2026-10-07 19:00:00' : null,
        'recorded_by' => $source === 'manual' ? User::factory()->create()->id : null,
    ]);

    expect(withdrawGuest($this))->toBe(MakeupWithdrawal::Kept)
        ->and(homeMark($this)->status)->toBe(AttendanceStatus::Makeup)
        ->and(AuditLogEntry::query()->where('action', 'attendance.auto_unmarked')->count())->toBe(0)
        ->and(CrossSectionSighting::query()->count())->toBe(1);
})->with(['a capture at another section' => ['capture'], 'another leader\'s entry' => ['manual']]);

it('keeps a mark that is no longer the automatic makeup', function (AttendanceStatus $status, AttendanceSource $source) {
    recordGuest($this);
    homeMark($this)->forceFill(['status' => $status, 'source' => $source])->save();

    expect(withdrawGuest($this))->toBe(MakeupWithdrawal::Kept);

    expect(homeMark($this)->status)->toBe($status)
        ->and(homeMark($this)->source)->toBe($source)
        ->and(AuditLogEntry::query()->where('action', 'attendance.auto_unmarked')->count())->toBe(0);
})->with([
    'changed by their own leader' => [AttendanceStatus::Makeup, AttendanceSource::Manual],
    'their own practice captured them since' => [AttendanceStatus::Present, AttendanceSource::Auto],
]);

it('leaves the makeup in an own week locked or confirmed since, removing only the entry', function (string $lock) {
    recordGuest($this);
    $home = SectionPractice::query()->where('section_id', $this->home->id)->sole();
    $lock === 'sent'
        ? WeeklyReport::factory()->sent()->create(['unit_id' => $this->home->id, 'week_start' => $this->week])
        : app(SectionPracticeRepository::class)->confirm($home, $this->leader->id);

    expect(withdrawGuest($this))->toBe(MakeupWithdrawal::Locked)
        ->and(homeMark($this)->status)->toBe(AttendanceStatus::Makeup)
        ->and(CrossSectionSighting::query()->count())->toBe(0);
})->with(['sent report' => ['sent'], 'confirmed practice' => ['confirmed']]);

it('keeps a makeup the entry never produced: their own mark was already there', function () {
    setHomeMark($this, AttendanceStatus::Present, AttendanceSource::Manual);
    recordGuest($this);

    expect(withdrawGuest($this))->toBe(MakeupWithdrawal::Kept)
        ->and(homeMark($this)->status)->toBe(AttendanceStatus::Present);
});

// Review L1: the makeup is written after the entry commits. An Undo landing in that gap finds no makeup to take back,
// so the makeup must see that its sighting is gone (read under a lock, inside its own transaction) and not be written.
it('writes no makeup when an Undo removes the entry between its commit and the makeup', function () {
    $real = app(CrossSectionSightingRepository::class);
    $t = $this;
    app()->instance(CrossSectionSightingRepository::class, new class($real, $t) implements CrossSectionSightingRepository
    {
        private bool $undone = false;

        public function __construct(private readonly CrossSectionSightingRepository $inner, private readonly object $t) {}

        public function trooperIdsSeenInWeek(array $trooperIds, CarbonInterface $weekStart): array
        {
            // The first read (before the makeup's transaction) still sees the entry; then the Undo lands, so the read
            // under the lock does not.
            $seen = $this->inner->trooperIdsSeenInWeek($trooperIds, $weekStart);

            if (! $this->undone) {
                $this->undone = true;
                expect(withdrawGuest($this->t))->toBe(MakeupWithdrawal::Kept);
            }

            return $seen;
        }

        public function record(SectionPractice $hostPractice, array $trooperIds, DateTimeInterface $seenFrom, DateTimeInterface $seenUntil): int
        {
            return $this->inner->record($hostPractice, $trooperIds, $seenFrom, $seenUntil);
        }

        public function recordManual(SectionPractice $hostPractice, int $trooperId, int $recordedBy, ?int $recordedByAdmin = null, ?int $recordedAsUnit = null): ?CrossSectionSighting
        {
            return $this->inner->recordManual($hostPractice, $trooperId, $recordedBy, $recordedByAdmin, $recordedAsUnit);
        }

        public function removeManual(int $hostPracticeId, int $trooperId): bool
        {
            return $this->inner->removeManual($hostPracticeId, $trooperId);
        }

        public function recordStaff(SectionPractice $hostPractice, int $trooperId, int $recordedBy, ?int $recordedByAdmin = null, ?int $recordedAsUnit = null): ?CrossSectionSighting
        {
            return $this->inner->recordStaff($hostPractice, $trooperId, $recordedBy, $recordedByAdmin, $recordedAsUnit);
        }

        public function removeStaff(int $hostPracticeId, int $trooperId): bool
        {
            return $this->inner->removeStaff($hostPracticeId, $trooperId);
        }

        public function staffEntriesFor(int $trooperId): Collection
        {
            return $this->inner->staffEntriesFor($trooperId);
        }

        public function staffEntriesInWeek(int $trooperId, CarbonInterface $weekStart): Collection
        {
            return $this->inner->staffEntriesInWeek($trooperId, $weekStart);
        }

        public function staffForHostPractice(int $hostPracticeId): Collection
        {
            return $this->inner->staffForHostPractice($hostPracticeId);
        }

        public function find(int $hostPracticeId, int $trooperId): ?CrossSectionSighting
        {
            return $this->inner->find($hostPracticeId, $trooperId);
        }

        public function forHostPractice(int $hostPracticeId): Collection
        {
            return $this->inner->forHostPractice($hostPracticeId);
        }

        public function existsForTrooperInWeek(int $trooperId, CarbonInterface $weekStart): bool
        {
            return $this->inner->existsForTrooperInWeek($trooperId, $weekStart);
        }

        public function visitsWithin(Unit $unit, CarbonInterface $firstWeek, CarbonInterface $lastWeek): Collection
        {
            return $this->inner->visitsWithin($unit, $firstWeek, $lastWeek);
        }
    });

    expect(recordGuest($this))->toBe('kept')
        ->and(CrossSectionSighting::query()->count())->toBe(0)
        ->and(homeMark($this))->toBeNull()
        ->and(AuditLogEntry::query()->where('action', 'attendance.auto_marked')->count())->toBe(0);
});
