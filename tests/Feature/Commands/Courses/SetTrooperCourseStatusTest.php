<?php

use App\Commands\Courses\SetTrooperCourseStatus;
use App\Models\AuditLogEntry;
use App\Models\Course;
use App\Models\CourseStatus;
use App\Models\Trooper;
use App\Models\TrooperCourse;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

// Story 6.2: the one audited writer of trooper_courses (AD-16, AD-22).

function setCourseStatus(User $actor, Trooper $trooper, Course $course, CourseStatus $status, ?string $awardedAt = null, ?string $reason = null): TrooperCourse
{
    return app(SetTrooperCourseStatus::class)->handle(
        $actor, $trooper->id, $course->id, $status, $awardedAt ? CarbonImmutable::parse($awardedAt, 'UTC') : null, $reason,
    );
}

it('creates the row the first time a status is set, and records who set it', function () {
    $actor = User::factory()->admin()->create();
    $trooper = Trooper::factory()->create();
    $course = Course::factory()->create();

    $row = setCourseStatus($actor, $trooper, $course, CourseStatus::Required);

    expect($row->status)->toBe(CourseStatus::Required)
        ->and($row->trooper_id)->toBe($trooper->id)
        ->and($row->course_id)->toBe($course->id)
        ->and($row->updated_by)->toBe($actor->id)
        ->and($row->awarded_at)->toBeNull()
        ->and(TrooperCourse::count())->toBe(1);
});

it('moves the same row on the next call rather than adding a second', function () {
    $actor = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();
    $trooper = Trooper::factory()->create();
    $course = Course::factory()->create();

    $first = setCourseStatus($actor, $trooper, $course, CourseStatus::Required);
    $second = setCourseStatus($other, $trooper, $course, CourseStatus::InProgress);

    expect(TrooperCourse::count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->status)->toBe(CourseStatus::InProgress)
        ->and($second->updated_by)->toBe($other->id);
});

it('keeps an award date only while the trooper is qualified', function () {
    $actor = User::factory()->admin()->create();
    $trooper = Trooper::factory()->create();
    $course = Course::factory()->create();

    $qualified = setCourseStatus($actor, $trooper, $course, CourseStatus::Qualified, '2026-09-14');
    expect($qualified->awarded_at->toDateString())->toBe('2026-09-14');

    $moved = setCourseStatus($actor, $trooper, $course, CourseStatus::InProgress, '2026-09-14');
    expect($moved->awarded_at)->toBeNull();

    $unlisted = setCourseStatus($actor, Trooper::factory()->create(), $course, CourseStatus::Required, '2026-09-14');
    expect($unlisted->awarded_at)->toBeNull();
});

it('qualifies a trooper with no date rather than guessing one', function () {
    $actor = User::factory()->admin()->create();

    $row = setCourseStatus($actor, Trooper::factory()->create(), Course::factory()->create(), CourseStatus::Qualified);

    expect($row->status)->toBe(CourseStatus::Qualified)->and($row->awarded_at)->toBeNull();
});

it('does not change the date object it was handed', function () {
    $actor = User::factory()->admin()->create();
    $given = CarbonImmutable::parse('2026-09-14 15:30:00', 'UTC');

    app(SetTrooperCourseStatus::class)->handle($actor, Trooper::factory()->create()->id, Course::factory()->create()->id, CourseStatus::Qualified, $given);

    expect($given->toDateTimeString())->toBe('2026-09-14 15:30:00');
});

it('records one audit_log entry per change, with the reason when one is given', function () {
    $actor = User::factory()->admin()->create();
    $trooper = Trooper::factory()->create();
    $course = Course::factory()->create();

    $row = setCourseStatus($actor, $trooper, $course, CourseStatus::Required);
    setCourseStatus($actor, $trooper, $course, CourseStatus::Qualified, '2026-09-14', 'Completed the final exam.');

    expect(AuditLogEntry::count())->toBe(2);

    $entry = AuditLogEntry::orderByDesc('id')->first();
    expect($entry->actor_id)->toBe($actor->id)
        ->and($entry->action)->toBe('trooper_course.status_set')
        ->and($entry->target_type)->toBe('TrooperCourse')
        ->and($entry->target_id)->toBe((string) $row->id)
        ->and($entry->reason)->toBe('Completed the final exam.')
        ->and($entry->content_digest)->not->toBeEmpty();
});

it('needs no reason', function () {
    $actor = User::factory()->admin()->create();

    setCourseStatus($actor, Trooper::factory()->create(), Course::factory()->create(), CourseStatus::Required);

    expect(AuditLogEntry::first()->reason)->toBeNull();
});

it('refuses a trooper that does not exist, leaving no row and no audit entry', function () {
    $actor = User::factory()->admin()->create();
    $course = Course::factory()->create();

    expect(fn () => app(SetTrooperCourseStatus::class)->handle($actor, 999999, $course->id, CourseStatus::Required))
        ->toThrow(QueryException::class);

    expect(TrooperCourse::count())->toBe(0)->and(AuditLogEntry::count())->toBe(0);
});

it('refuses a course that does not exist, leaving no row and no audit entry', function () {
    $actor = User::factory()->admin()->create();
    $trooper = Trooper::factory()->create();

    expect(fn () => app(SetTrooperCourseStatus::class)->handle($actor, $trooper->id, 999999, CourseStatus::Required))
        ->toThrow(QueryException::class);

    expect(TrooperCourse::count())->toBe(0)->and(AuditLogEntry::count())->toBe(0);
});

it('leaves other troopers and other courses alone', function () {
    $actor = User::factory()->admin()->create();
    $a = Trooper::factory()->create();
    $b = Trooper::factory()->create();
    $courseOne = Course::factory()->create();
    $courseTwo = Course::factory()->create();

    setCourseStatus($actor, $a, $courseOne, CourseStatus::Qualified, '2026-09-14');
    setCourseStatus($actor, $a, $courseTwo, CourseStatus::Required);
    setCourseStatus($actor, $b, $courseOne, CourseStatus::InProgress);

    expect(TrooperCourse::count())->toBe(3)
        ->and(TrooperCourse::where('trooper_id', $a->id)->where('course_id', $courseOne->id)->first()->status)->toBe(CourseStatus::Qualified);
});

it('refuses an award date that has not happened yet, leaving no row and no audit entry', function () {
    $actor = User::factory()->admin()->create();
    $trooper = Trooper::factory()->create();
    $course = Course::factory()->create();

    expect(fn () => app(SetTrooperCourseStatus::class)->handle($actor, $trooper->id, $course->id, CourseStatus::Qualified, CarbonImmutable::now('UTC')->addDay()))
        ->toThrow(InvalidArgumentException::class);

    expect(TrooperCourse::count())->toBe(0)->and(AuditLogEntry::count())->toBe(0);
});

it('accepts today as the award date, and ignores a future date on a status that holds none', function () {
    $actor = User::factory()->admin()->create();
    $trooper = Trooper::factory()->create();

    $today = app(SetTrooperCourseStatus::class)->handle($actor, $trooper->id, Course::factory()->create()->id, CourseStatus::Qualified, CarbonImmutable::now('UTC'));
    $inProgress = app(SetTrooperCourseStatus::class)->handle($actor, $trooper->id, Course::factory()->create()->id, CourseStatus::InProgress, CarbonImmutable::now('UTC')->addDays(30));

    expect($today->awarded_at->toDateString())->toBe(CarbonImmutable::now('UTC')->toDateString())
        ->and($inProgress->awarded_at)->toBeNull();
});
