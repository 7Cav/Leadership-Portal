<?php

use App\Commands\Practices\SetPracticeSlot;
use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\BattalionSetting;
use App\Models\ChangelogEntry;
use App\Models\CounselingRecord;
use App\Models\SectionPractice;
use App\Models\SectionPracticeSlot;
use App\Models\StaffReport;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Repositories\AttendanceRepository;
use App\Repositories\AuditedBatch;
use App\Repositories\AuditLogRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** An entry written before details were stored with it: no `details`, only its target. */
function oldEntryAbout(User $actor, Model $target, string $action): AuditLogEntry
{
    return AuditLogEntry::factory()->create([
        'actor_id' => $actor->id,
        'action' => $action,
        'target_type' => class_basename($target),
        'target_id' => (string) $target->getKey(),
    ]);
}

/**
 * Each entry on the page as the page gets it, by entry id.
 *
 * @return array<int, array<string, mixed>>
 */
function auditRowsOn($test): array
{
    $rows = null;
    $test->actingAs($test->admin)->get('/settings/audit-log')->assertOk()->assertInertia(function ($page) use (&$rows) {
        $rows = collect($page->toArray()['props']['entries']['data'])->keyBy('id')->all();
    });

    return $rows;
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actor = User::factory()->create();
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'name' => '1st Section', 'callsign' => '1/1/F/1-7', 'path' => '800/']);
    $this->trooper = Trooper::factory()->create(['rank_short' => 'PVT', 'username' => 'Novak.E']);
    $this->week = CarbonImmutable::parse('2026-10-24 00:00:00', 'UTC');
});

describe('subjects of entries written before details were stored', function () {
    it('names whose staff report it was and for which week', function () {
        $report = StaffReport::query()->create(['trooper_id' => $this->trooper->id, 'unit_id' => $this->section->id, 'week_start' => CarbonImmutable::parse('2026-10-17 00:00:00', 'UTC'), 'attended' => true, 'practices' => [], 'sent_at' => now()]);
        $entry = oldEntryAbout($this->actor, $report, 'staff_report.sent');

        expect(auditRowsOn($this)[$entry->id]['subject'])->toBe('Staff report of PVT Novak.E, week 17OCT26 to 23OCT26');
    });

    it('names the unit and week of a weekly report, a practice and a practice mark', function () {
        $report = oldEntryAbout($this->actor, WeeklyReport::factory()->create(['unit_id' => $this->section->id, 'week_start' => $this->week]), 'weekly_report.sent');
        $practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->week]);
        $week = oldEntryAbout($this->actor, $practice, 'practice_week.changed');
        $mark = oldEntryAbout($this->actor, Attendance::query()->create(['trooper_id' => $this->trooper->id, 'section_practice_id' => $practice->id, 'status' => AttendanceStatus::Present, 'source' => AttendanceSource::Manual]), 'attendance.marked');

        $rows = auditRowsOn($this);

        expect($rows[$report->id]['subject'])->toBe('Weekly report 1/1/F/1-7, week 24OCT26 to 30OCT26')
            ->and($rows[$week->id]['subject'])->toBe('1/1/F/1-7 practice, week 24OCT26 to 30OCT26')
            ->and($rows[$mark->id]['subject'])->toBe('Practice mark for PVT Novak.E, 1/1/F/1-7, week 24OCT26 to 30OCT26');
    });

    it('names a counseling record, a changelog entry, a practice slot and a presence minimum', function () {
        $dcs = oldEntryAbout($this->actor, CounselingRecord::factory()->create(['trooper_id' => $this->trooper->id, 'counseled_on' => '2026-10-10']), 'counseling.filed');
        $changelog = oldEntryAbout($this->actor, ChangelogEntry::query()->create(['date' => '2026-10-30', 'body' => 'Added: practice slots', 'created_by' => $this->actor->id]), 'changelog.created');
        $slot = oldEntryAbout($this->actor, SectionPracticeSlot::query()->create(['section_id' => $this->section->id, 'weekday' => 3, 'start_minute' => 1439]), 'practice_slot.set');
        $minimum = oldEntryAbout($this->actor, BattalionSetting::query()->create(['unit_id' => $this->section->id, 'presence_minimum_minutes' => 60]), 'presence_minimum.set');

        $rows = auditRowsOn($this);

        expect($rows[$dcs->id]['subject'])->toBe('DCS for PVT Novak.E dated 10OCT26')
            ->and($rows[$changelog->id]['subject'])->toBe('Changelog entry 30OCT26 "Added: practice slots"')
            ->and($rows[$slot->id]['subject'])->toBe('Practice slot 1/1/F/1-7')
            ->and($rows[$minimum->id]['subject'])->toBe('Presence minimum 1/1/F/1-7')
            ->and($rows[$slot->id]['changes'])->toBe([]);
    });

    it('reads the subjects once per type for the page, not once per entry', function () {
        $queriesFor = function (int $perType): int {
            AuditLogEntry::query()->delete();
            foreach (range(1, $perType) as $n) {
                $trooper = Trooper::factory()->create();
                $section = Unit::factory()->create(['type' => UnitType::Section, 'path' => "9{$perType}{$n}/"]);
                $practice = SectionPractice::factory()->create(['section_id' => $section->id]);
                oldEntryAbout($this->actor, StaffReport::query()->create(['trooper_id' => $trooper->id, 'unit_id' => $section->id, 'week_start' => $this->week, 'attended' => true, 'practices' => [], 'sent_at' => now()]), 'staff_report.sent');
                oldEntryAbout($this->actor, Attendance::query()->create(['trooper_id' => $trooper->id, 'section_practice_id' => $practice->id, 'status' => AttendanceStatus::Missed, 'source' => AttendanceSource::Manual]), 'attendance.marked');
                oldEntryAbout($this->actor, CounselingRecord::factory()->create(['trooper_id' => $trooper->id]), 'counseling.filed');
                oldEntryAbout($this->actor, SectionPracticeSlot::query()->create(['section_id' => $section->id, 'weekday' => 1, 'start_minute' => 60]), 'practice_slot.set');
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            auditRowsOn($this);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        expect($queriesFor(4))->toBe($queriesFor(1));
    });
});

describe('details written with new entries', function () {
    it('stores a practice slot change with its before and after, and keeps the subject once the slot is cleared', function () {
        $slots = app(SetPracticeSlot::class);
        $slots->handle($this->admin, $this->section->id, 3, 23 * 60 + 59);
        $slots->handle($this->admin, $this->section->id, 5, 19 * 60);
        $slots->handle($this->admin, $this->section->id, null, null);

        $entries = AuditLogEntry::query()->orderBy('id')->get();

        expect($entries->pluck('details')->all())->toBe([
            ['subject' => 'Practice slot 1/1/F/1-7', 'changes' => [['field' => 'Slot', 'from' => 'none', 'to' => 'Wed 2359Z']]],
            ['subject' => 'Practice slot 1/1/F/1-7', 'changes' => [['field' => 'Slot', 'from' => 'Wed 2359Z', 'to' => 'Fri 1900Z']]],
            ['subject' => 'Practice slot 1/1/F/1-7', 'changes' => [['field' => 'Slot', 'from' => 'Fri 1900Z', 'to' => 'none']]],
        ]);

        // The slot is gone, yet the page still names it from what the entry stored.
        expect(collect(auditRowsOn($this))->pluck('subject')->unique()->all())->toBe(['Practice slot 1/1/F/1-7']);
    });

    it('stores each practice mark of a batch with the trooper and the before and after', function () {
        $practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->week]);
        $attendance = app(AttendanceRepository::class);
        $mark = fn (AttendanceStatus $status) => app(AuditLogRepository::class)->recordBatchWithMutation($this->actor->id, 'attendance.marked', null, fn () => new AuditedBatch(
            $practice,
            $attendance->markPractice($practice, $this->trooper->id, $status, AttendanceSource::Manual),
        ));

        $mark(AttendanceStatus::Present);
        $mark(AttendanceStatus::Makeup);

        $details = AuditLogEntry::query()->orderBy('id')->pluck('details')->all();

        expect($details[0]['subject'])->toBe('1/1/F/1-7 practice, week 24OCT26 to 30OCT26')
            ->and($details[0]['changes'])->toContain(['field' => 'PVT Novak.E: Practice', 'from' => 'none', 'to' => 'Attended'])
            ->and($details[1]['changes'])->toBe([['field' => 'PVT Novak.E: Practice', 'from' => 'Attended', 'to' => 'Make-up complete']]);
    });

    it('stores the subject of a changelog edit with its old and new text', function () {
        $entry = ChangelogEntry::query()->create(['date' => '2026-10-30', 'body' => 'Added: slots', 'created_by' => $this->actor->id]);

        app(AuditLogRepository::class)->recordWithMutation($this->actor->id, 'changelog.updated', null, fn () => tap($entry)->update(['body' => 'Added: practice slots']));

        expect(AuditLogEntry::query()->sole()->details)->toBe([
            'subject' => 'Changelog entry 30OCT26 "Added: practice slots"',
            'changes' => [['field' => 'Text', 'from' => 'Added: slots', 'to' => 'Added: practice slots']],
        ]);
    });

    it('never stores free text such as a counseling reason', function () {
        $record = CounselingRecord::factory()->create(['trooper_id' => $this->trooper->id, 'counseled_on' => '2026-10-10']);

        app(AuditLogRepository::class)->recordWithMutation($this->actor->id, 'counseling.corrected', 'typo', fn () => tap($record, fn ($record) => $record->forceFill(['reason' => 'A private reason', 'counseled_on' => '2026-10-11'])->save()));

        $details = AuditLogEntry::query()->sole()->details;

        expect($details['subject'])->toBe('DCS for PVT Novak.E dated 11OCT26')
            ->and($details['changes'])->toBe([['field' => 'Dated', 'from' => '10OCT26', 'to' => '11OCT26']])
            ->and(json_encode($details))->not->toContain('private');
    });
});
