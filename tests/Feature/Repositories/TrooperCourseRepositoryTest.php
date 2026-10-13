<?php

use App\Models\Course;
use App\Models\CourseStatus;
use App\Models\Trooper;
use App\Models\TrooperCourse;
use App\Models\User;
use App\Repositories\TrooperCourseRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Story 6.2: reads come back fully loaded, in the catalog's order, and never another trooper's.

it('returns a trooper\'s statuses with each course loaded, so nothing lazy-loads', function () {
    $trooper = Trooper::factory()->create();
    TrooperCourse::factory()->count(3)->create(['trooper_id' => $trooper->id]);

    $rows = app(TrooperCourseRepository::class)->forTrooper($trooper->id);

    expect($rows)->toHaveCount(3);
    // `preventLazyLoading()` is on outside production: reading `course` here would throw if it were not loaded.
    expect($rows->every(fn (TrooperCourse $row) => $row->course instanceof Course))->toBeTrue();
});

it('orders them as the catalog is ordered: sort_order, then code', function () {
    $trooper = Trooper::factory()->create();
    $late = Course::factory()->create(['code' => 'AAA', 'sort_order' => 30]);
    $tieB = Course::factory()->create(['code' => 'BBB', 'sort_order' => 10]);
    $tieA = Course::factory()->create(['code' => 'ABC', 'sort_order' => 10]);

    foreach ([$late, $tieB, $tieA] as $course) {
        TrooperCourse::factory()->create(['trooper_id' => $trooper->id, 'course_id' => $course->id]);
    }

    expect(app(TrooperCourseRepository::class)->forTrooper($trooper->id)->pluck('course.code')->all())
        ->toBe(['ABC', 'BBB', 'AAA']);
});

it('never returns another trooper\'s statuses', function () {
    $trooper = Trooper::factory()->create();
    TrooperCourse::factory()->create(['trooper_id' => Trooper::factory()->create()->id]);

    expect(app(TrooperCourseRepository::class)->forTrooper($trooper->id))->toHaveCount(0);
});

it('finds the row for a pair, with the course loaded, or null', function () {
    $row = TrooperCourse::factory()->create();

    $found = app(TrooperCourseRepository::class)->find($row->trooper_id, $row->course_id);

    expect($found?->id)->toBe($row->id)
        ->and($found->course->id)->toBe($row->course_id)
        ->and(app(TrooperCourseRepository::class)->find($row->trooper_id, 999999))->toBeNull()
        ->and(app(TrooperCourseRepository::class)->find(999999, $row->course_id))->toBeNull();
});

it('sets a row with an atomic upsert: the first call inserts, the second moves the same row', function () {
    $trooper = Trooper::factory()->create();
    $course = Course::factory()->create();
    $user = User::factory()->create();
    $repository = app(TrooperCourseRepository::class);

    $row = $repository->set($trooper->id, $course->id, CourseStatus::Required, null, $user->id);
    expect($row->status)->toBe(CourseStatus::Required)->and($row->course->id)->toBe($course->id);

    $updated = $repository->set($trooper->id, $course->id, CourseStatus::Qualified, CarbonImmutable::parse('2026-09-14', 'UTC'), $user->id);

    expect($updated->id)->toBe($row->id)
        ->and(TrooperCourse::count())->toBe(1)
        ->and($updated->status)->toBe(CourseStatus::Qualified)
        ->and($updated->awarded_at->toDateString())->toBe('2026-09-14');
});

// Story 6.9: one read for a whole unit's troopers, still fully loaded and in the catalog's order.

it('returns the rows of several troopers in one read, and nobody else\'s', function () {
    $a = Trooper::factory()->create();
    $b = Trooper::factory()->create();
    $outsider = Trooper::factory()->create();
    TrooperCourse::factory()->create(['trooper_id' => $a->id]);
    TrooperCourse::factory()->create(['trooper_id' => $b->id]);
    TrooperCourse::factory()->create(['trooper_id' => $outsider->id]);

    $rows = app(TrooperCourseRepository::class)->forTroopers([$a->id, $b->id]);

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('trooper_id')->all())->toBe([$a->id, $b->id]);
});

it('orders several troopers\' rows by trooper, then course, so the result never shuffles', function () {
    $first = Trooper::factory()->create();
    $second = Trooper::factory()->create();
    $one = Course::factory()->create();
    $two = Course::factory()->create();

    foreach ([[$second, $two], [$first, $two], [$second, $one]] as [$trooper, $course]) {
        TrooperCourse::factory()->create(['trooper_id' => $trooper->id, 'course_id' => $course->id]);
    }

    $rows = app(TrooperCourseRepository::class)->forTroopers([$first->id, $second->id]);

    expect($rows->map(fn (TrooperCourse $row) => [$row->trooper_id, $row->course_id])->all())
        ->toBe([[$first->id, $two->id], [$second->id, $one->id], [$second->id, $two->id]]);
});

it('loads no course with the rows of several troopers', function () {
    TrooperCourse::factory()->create(['trooper_id' => Trooper::factory()->create()->id]);

    $row = app(TrooperCourseRepository::class)->forTroopers([TrooperCourse::first()->trooper_id])->first();

    expect($row->relationLoaded('course'))->toBeFalse();
});

it('returns nothing, without a query, for no troopers', function () {
    DB::enableQueryLog();

    expect(app(TrooperCourseRepository::class)->forTroopers([]))->toHaveCount(0)
        ->and(DB::getQueryLog())->toBe([]);
});
