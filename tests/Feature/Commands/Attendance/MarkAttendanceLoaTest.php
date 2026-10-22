<?php

use App\Commands\Attendance\AttendanceMark;
use App\Commands\Attendance\InvalidAttendanceMarkException;
use App\Commands\Attendance\MarkAttendance;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Loa\LoaCoverage;
use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\AuditLogEntry;
use App\Models\Loa;
use App\Models\LoaType;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Story 3.4: attendance status `loa` is derived from the synced leave, never hand-entered, and a leave that
// needs review is fail-safe (AD-14, AD-4). The Cav week under test runs Saturday 2026-10-03 to Friday 2026-10-09.

beforeEach(function () {
    $this->section = Unit::factory()->create(['type' => UnitType::Section]);
    $this->leader = User::factory()->commanding($this->section)->create();

    $this->moment = CarbonImmutable::parse('2026-10-07 18:00:00', 'UTC');
    $this->weekStart = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
    $this->travelTo($this->moment);

    $this->troopers = Trooper::factory()->count(3)->create(['primary_section_id' => $this->section->id]);
});

function markLoa(User $actor, int $sectionId, DateTimeImmutable $moment, array $marks)
{
    return app(MarkAttendanceHandler::class)->handle($actor, new MarkAttendance($sectionId, $moment, ...$marks));
}

function leave(Trooper $trooper, string $starts, ?string $ends, array $state = []): Loa
{
    return Loa::factory()->create(['trooper_id' => $trooper->id, 'type' => LoaType::Loa, 'starts_at' => $starts, 'ends_at' => $ends, ...$state]);
}

describe('a trooper whose leave covers the week', function () {
    it('derives the loa status from the leave, and no mark is stored', function () {
        leave($this->troopers[0], '2026-10-01', '2026-10-20');

        $cover = app(LoaCoverage::class)->forTrooper($this->troopers[0]->id, $this->weekStart);

        expect($cover->derivedAttendanceStatus())->toBe(AttendanceStatus::Loa)
            ->and(Attendance::query()->count())->toBe(0);
    });

    it('cannot be given a practice status by hand', function (AttendanceStatus $status) {
        leave($this->troopers[0], '2026-10-01', '2026-10-20');

        expect(fn () => markLoa($this->leader, $this->section->id, $this->moment, [new AttendanceMark($this->troopers[0]->id, $status)]))
            ->toThrow(InvalidAttendanceMarkException::class, 'on leave this week');
    })->with([
        'present' => AttendanceStatus::Present,
        'missed' => AttendanceStatus::Missed,
        'makeup' => AttendanceStatus::Makeup,
        'attached' => AttendanceStatus::Attached,
    ]);

    it('refuses the whole batch and writes nothing, not even the practice row, the other marks or the audit entry', function () {
        leave($this->troopers[1], '2026-10-01', '2026-10-20');

        expect(fn () => markLoa($this->leader, $this->section->id, $this->moment, [
            new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present),
            new AttendanceMark($this->troopers[1]->id, AttendanceStatus::Missed),
        ]))->toThrow(InvalidAttendanceMarkException::class);

        expect(SectionPractice::query()->count())->toBe(0)
            ->and(Attendance::query()->count())->toBe(0)
            ->and(AuditLogEntry::query()->count())->toBe(0);
    });

    it('carries every covered trooper on the refusal, and names no one in its message', function () {
        leave($this->troopers[0], '2026-10-01', '2026-10-20');
        leave($this->troopers[2], '2026-10-04', '2026-10-05');

        try {
            markLoa($this->leader, $this->section->id, $this->moment, [
                new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present),
                new AttendanceMark($this->troopers[1]->id, AttendanceStatus::Present),
                new AttendanceMark($this->troopers[2]->id, AttendanceStatus::Present),
            ]);
        } catch (InvalidAttendanceMarkException $e) {
            // The ids ride on the exception, like notPlacedInSection, so the autosaving rollcall screen can
            // drop only the refused troopers and keep saving the others. The message names no one.
            expect($e->trooperIds)->toBe([$this->troopers[0]->id, $this->troopers[2]->id])
                ->and($e->getMessage())->toContain('on leave this week')
                ->and($e->getMessage())->not->toMatch('/\d/');

            return;
        }

        $this->fail('The batch should have been refused.');
    });

    it('may still have their rollcall signature recorded, which is a separate fact', function () {
        leave($this->troopers[0], '2026-10-01', '2026-10-20');

        markLoa($this->leader, $this->section->id, $this->moment, [new AttendanceMark($this->troopers[0]->id, rollcall: RollcallSignature::CannotAttend)]);

        $row = Attendance::query()->sole();

        expect($row->rollcall)->toBe(RollcallSignature::CannotAttend)
            ->and($row->status)->toBeNull()
            ->and($row->source)->toBeNull();
    });

    it('is covered by an open-ended leave, which runs until a return is posted', function () {
        leave($this->troopers[0], '2026-08-01', null, ['type' => LoaType::Eloa]);

        expect(fn () => markLoa($this->leader, $this->section->id, $this->moment, [new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present)]))
            ->toThrow(InvalidAttendanceMarkException::class);
    });

    it('is covered whenever the leave touches any day of the Cav week, the one definition every screen shares (4.4)', function () {
        leave($this->troopers[0], '2026-10-08', '2026-10-30');

        expect(fn () => markLoa($this->leader, $this->section->id, $this->moment, [new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present)]))
            ->toThrow(InvalidAttendanceMarkException::class);
    });
});

describe('a trooper whose leave does not cover the week', function () {
    it('is marked as usual when the leave ended before the week began', function () {
        leave($this->troopers[0], '2026-09-20', '2026-10-02');

        markLoa($this->leader, $this->section->id, $this->moment, [new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present)]);

        expect(Attendance::query()->sole()->status)->toBe(AttendanceStatus::Present);
    });

    it('is marked as usual when the leave begins the week after', function () {
        leave($this->troopers[0], '2026-10-10', '2026-10-30');

        markLoa($this->leader, $this->section->id, $this->moment, [new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Missed)]);

        expect(Attendance::query()->sole()->status)->toBe(AttendanceStatus::Missed);
    });

    it('is marked as usual when someone else is on leave', function () {
        leave($this->troopers[1], '2026-10-01', '2026-10-20');

        markLoa($this->leader, $this->section->id, $this->moment, [new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present)]);

        expect(Attendance::query()->sole()->trooper_id)->toBe($this->troopers[0]->id);
    });

    it('is marked as usual when no leave is cached at all, so a missing LOA sync blocks nothing', function () {
        expect(Loa::query()->count())->toBe(0);

        markLoa($this->leader, $this->section->id, $this->moment, [
            new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present),
            new AttendanceMark($this->troopers[1]->id, AttendanceStatus::Makeup),
        ]);

        expect(Attendance::query()->count())->toBe(2);
    });
});

describe('a leave that needs review (AD-4)', function () {
    it('derives no status: it is neither away nor absent', function () {
        Loa::factory()->needsReview()->create(['trooper_id' => $this->troopers[0]->id]);

        $cover = app(LoaCoverage::class)->forTrooper($this->troopers[0]->id, $this->weekStart);

        expect($cover->derivedAttendanceStatus())->toBeNull()
            ->and($cover->suppressesAtRisk())->toBeTrue();
    });

    it('does not block a leader marking the trooper, and the mark is the leader\'s own', function () {
        Loa::factory()->needsReview()->create(['trooper_id' => $this->troopers[0]->id]);

        markLoa($this->leader, $this->section->id, $this->moment, [new AttendanceMark($this->troopers[0]->id, AttendanceStatus::Present)]);

        $row = Attendance::query()->sole();

        expect($row->status)->toBe(AttendanceStatus::Present)
            ->and($row->source)->toBe(AttendanceSource::Manual);
    });

    it('yields to a cleanly read leave that covers the week', function () {
        Loa::factory()->needsReview()->create(['trooper_id' => $this->troopers[0]->id]);
        leave($this->troopers[0], '2026-10-01', '2026-10-20');

        expect(app(LoaCoverage::class)->forTrooper($this->troopers[0]->id, $this->weekStart)->derivedAttendanceStatus())->toBe(AttendanceStatus::Loa);
    });
});

// 7.6: capture's pre-mark reads the same coverage, so a trooper on leave who shows up on the server is not
// given an auto `present` that the leader could then not change.
describe('capture pre-marking a trooper on leave', function () {
    it('skips a trooper whose leave covers the week, counting them as left untouched', function () {
        leave($this->troopers[0], '2026-10-01', '2026-10-20');
        $practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->weekStart]);

        $result = app(MarkAttendanceHandler::class)->preMark($practice, [$this->troopers[0]->id, $this->troopers[1]->id]);

        expect([$result->marked, $result->keptManual, $result->notPlaced])->toBe([1, 1, 0])
            ->and(Attendance::query()->pluck('trooper_id')->all())->toBe([$this->troopers[1]->id]);
    });

    it('still pre-marks a trooper whose leave needs review, as a leader may mark them by hand', function () {
        Loa::factory()->needsReview()->create(['trooper_id' => $this->troopers[0]->id]);
        $practice = SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->weekStart]);

        $result = app(MarkAttendanceHandler::class)->preMark($practice, [$this->troopers[0]->id]);

        expect($result->marked)->toBe(1)
            ->and(Attendance::query()->sole()->source)->toBe(AttendanceSource::Auto);
    });
});

it('asks for the leave of every trooper in a batch in one query', function () {
    foreach ($this->troopers as $trooper) {
        leave($trooper, '2026-09-01', '2026-09-05');
    }

    DB::enableQueryLog();
    markLoa($this->leader, $this->section->id, $this->moment, $this->troopers->map(fn (Trooper $t) => new AttendanceMark($t->id, AttendanceStatus::Present))->all());
    $leaveQueries = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'from "loas"') || str_contains($q['query'], 'from `loas`'))->count();

    expect($leaveQueries)->toBe(1);
});

it('derives nothing for a trooper with no leave at all', function () {
    expect(app(LoaCoverage::class)->forTrooper($this->troopers[0]->id, $this->weekStart)->derivedAttendanceStatus())->toBeNull();
});
