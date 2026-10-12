<?php

use App\Models\Course;
use App\Models\CourseStatus;
use App\Models\Trooper;
use App\Models\TrooperCourse;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Story 6.2: the portal-owned link between a trooper and a course (AD-22).

it('creates trooper_courses with the key, the pair, the status, the award date and the setter', function () {
    expect(Schema::hasColumns('trooper_courses', [
        'id', 'trooper_id', 'course_id', 'status', 'awarded_at', 'updated_by', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('makes the (trooper, course) pair unique', function () {
    $unique = collect(Schema::getIndexes('trooper_courses'))->first(fn ($index) => $index['unique'] && ! $index['primary']);

    expect($unique['columns'])->toBe(['trooper_id', 'course_id']);
});

it('rejects a second row for the same trooper and course, rather than upserting', function () {
    $row = TrooperCourse::factory()->create();

    expect(fn () => TrooperCourse::factory()->create(['trooper_id' => $row->trooper_id, 'course_id' => $row->course_id]))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(TrooperCourse::count())->toBe(1);
});

it('allows one trooper several courses, and one course several troopers', function () {
    $trooper = Trooper::factory()->create();
    $course = Course::factory()->create();

    TrooperCourse::factory()->create(['trooper_id' => $trooper->id]);
    TrooperCourse::factory()->create(['trooper_id' => $trooper->id]);
    TrooperCourse::factory()->create(['course_id' => $course->id]);
    TrooperCourse::factory()->create(['course_id' => $course->id]);

    expect(TrooperCourse::count())->toBe(4);
});

it('rejects a trooper or a course that does not exist', function () {
    expect(fn () => TrooperCourse::factory()->create(['trooper_id' => 999999]))->toThrow(QueryException::class)
        ->and(fn () => TrooperCourse::factory()->create(['course_id' => 999999]))->toThrow(QueryException::class)
        ->and(TrooperCourse::count())->toBe(0);
});

it('stops a trooper or a course that has statuses from being deleted', function () {
    $row = TrooperCourse::factory()->create();

    expect(fn () => DB::table('troopers')->where('id', $row->trooper_id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('courses')->where('id', $row->course_id)->delete())->toThrow(QueryException::class);
});

it('refuses a status the enum does not know', function () {
    expect(fn () => DB::table('trooper_courses')->insert([
        'trooper_id' => Trooper::factory()->create()->id,
        'course_id' => Course::factory()->create()->id,
        'status' => 'graduated',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('keeps the status as one enum, with none meaning neither held nor required', function () {
    expect(array_map(fn (CourseStatus $status) => $status->value, CourseStatus::cases()))
        ->toBe(['qualified', 'in_progress', 'required', 'none'])
        ->and(CourseStatus::Qualified->holds())->toBeTrue()
        ->and(CourseStatus::InProgress->holds())->toBeFalse()
        ->and(CourseStatus::Required->holds())->toBeFalse()
        ->and(CourseStatus::None->holds())->toBeFalse();
});

it('casts the status and the award date', function () {
    $row = TrooperCourse::factory()->qualified('2026-09-14')->create()->fresh();

    expect($row->status)->toBe(CourseStatus::Qualified)
        ->and($row->awarded_at->toDateString())->toBe('2026-09-14');
});

it('nulls who set it, rather than blocking, when that user is deleted', function () {
    $user = User::factory()->create();
    $row = TrooperCourse::factory()->create(['updated_by' => $user->id]);

    $user->delete();

    expect($row->fresh()->updated_by)->toBeNull();
});
