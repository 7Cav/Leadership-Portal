<?php

use App\Models\AreaOfOperation;
use App\Models\Course;
use App\Repositories\CourseRepository;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

it('returns the catalog ordered by sort_order', function () {
    Course::factory()->create(['code' => 'B', 'sort_order' => 20]);
    Course::factory()->create(['code' => 'C', 'sort_order' => 30]);
    Course::factory()->create(['code' => 'A', 'sort_order' => 10]);

    expect(app(CourseRepository::class)->all()->pluck('code')->all())->toBe(['A', 'B', 'C']);
});

it('breaks a sort_order tie on code so the order is never a shuffle', function () {
    Course::factory()->create(['code' => 'ZED', 'sort_order' => 5]);
    Course::factory()->create(['code' => 'ALPHA', 'sort_order' => 5]);
    Course::factory()->create(['code' => 'MIKE', 'sort_order' => 5]);

    expect(app(CourseRepository::class)->all()->pluck('code')->all())->toBe(['ALPHA', 'MIKE', 'ZED']);
});

it('returns an empty collection when no courses exist', function () {
    expect(app(CourseRepository::class)->all()->all())->toBe([]);
});

it('creates a course with its area of operation', function () {
    $course = app(CourseRepository::class)->create('HLL-SNP', 'Sniper Course', AreaOfOperation::HllWw2, 'Long range.', 40);

    expect($course->category)->toBe(AreaOfOperation::HllWw2)
        ->and($course->fresh()->category)->toBe(AreaOfOperation::HllWw2)
        ->and($course->description)->toBe('Long range.')
        ->and($course->sort_order)->toBe(40);
});

it('finds a course by its code', function () {
    $course = Course::factory()->create(['code' => 'NCOA-WLC1']);
    $repo = app(CourseRepository::class);

    expect($repo->findByCode('NCOA-WLC1')?->id)->toBe($course->id)
        ->and($repo->findByCode('NOPE'))->toBeNull();
});

it('has a label for every area of operation', function () {
    foreach (AreaOfOperation::cases() as $area) {
        expect($area->label())->toBeString()->not->toBeEmpty();
    }
});

it('refuses a category the enum does not know, at the database', function () {
    $insert = fn () => DB::table('courses')->insert([
        'code' => 'X', 'name' => 'X', 'category' => 'not-an-ao', 'sort_order' => 1,
    ]);

    // SQLite (the test database) enforces an enum column as a CHECK constraint; MySQL as the ENUM type.
    expect($insert)->toThrow(QueryException::class);
});

it('rejects a duplicate code at the database instead of coalescing it', function () {
    $repo = app(CourseRepository::class);
    $repo->create('BC', 'Boot Camp', AreaOfOperation::General, null, 10);

    expect(fn () => $repo->create('BC', 'Another Boot Camp', AreaOfOperation::General, null, 20))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(Course::count())->toBe(1)
        ->and(Course::first()->name)->toBe('Boot Camp');
});

it('groups the catalog by area of operation before sort_order, so a late General course stays with General', function () {
    Course::factory()->create(['code' => 'F1', 'category' => AreaOfOperation::Foxhole, 'sort_order' => 10]);
    Course::factory()->create(['code' => 'G-LATE', 'category' => AreaOfOperation::General, 'sort_order' => 9999]);
    Course::factory()->create(['code' => 'A3', 'category' => AreaOfOperation::Arma3, 'sort_order' => 5]);
    Course::factory()->create(['code' => 'G-EARLY', 'category' => AreaOfOperation::General, 'sort_order' => 1]);

    expect(app(CourseRepository::class)->all()->pluck('code')->all())->toBe(['G-EARLY', 'G-LATE', 'A3', 'F1']);
});

it('accepts every AreaOfOperation case in the category column, so the migration list cannot drift from the enum', function () {
    foreach (AreaOfOperation::cases() as $i => $area) {
        DB::table('courses')->insert(['code' => "C{$i}", 'name' => 'X', 'category' => $area->value, 'sort_order' => $i]);
    }

    expect(DB::table('courses')->count())->toBe(count(AreaOfOperation::cases()));
});
