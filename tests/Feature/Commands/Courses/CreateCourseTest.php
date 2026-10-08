<?php

use App\Commands\Courses\CreateCourse;
use App\Models\AreaOfOperation;
use App\Models\AuditLogEntry;
use App\Models\Course;
use App\Models\User;
use App\Repositories\CourseRepository;
use Database\Seeders\CourseCatalogSeeder;
use Illuminate\Database\UniqueConstraintViolationException;

it('creates the course through the repository', function () {
    $actor = User::factory()->admin()->create();

    $course = app(CreateCourse::class)->handle($actor, 'ODS', 'Officer Development School', AreaOfOperation::General, 40);

    expect($course->code)->toBe('ODS')
        ->and($course->category)->toBe(AreaOfOperation::General)
        ->and($course->sort_order)->toBe(40)
        ->and(Course::count())->toBe(1);
});

it('trims and upper-cases the code so a duplicate means the same on every database', function () {
    $actor = User::factory()->admin()->create();
    app(CreateCourse::class)->handle($actor, '  ncoa-1 ', ' NCOA Phase I ', AreaOfOperation::General, 10);

    expect(Course::first()->code)->toBe('NCOA-1')
        ->and(Course::first()->name)->toBe('NCOA Phase I');

    expect(fn () => app(CreateCourse::class)->handle($actor, 'Ncoa-1', 'Impostor', AreaOfOperation::General, 20))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('records exactly one audit_log entry for the created course', function () {
    $actor = User::factory()->admin()->create();

    $course = app(CreateCourse::class)->handle($actor, 'ODS', 'Officer Development School', AreaOfOperation::General, 10);

    expect(AuditLogEntry::count())->toBe(1);

    $entry = AuditLogEntry::first();
    expect($entry->actor_id)->toBe($actor->id)
        ->and($entry->action)->toBe('course.created')
        ->and($entry->target_type)->toBe('Course')
        ->and($entry->target_id)->toBe((string) $course->id)
        ->and($entry->reason)->toBeNull()
        ->and($entry->content_digest)->not->toBeEmpty();
});

it('refuses a duplicate code and leaves no second course or audit entry behind', function () {
    $actor = User::factory()->admin()->create();
    app(CreateCourse::class)->handle($actor, 'ODS', 'Officer Development School', AreaOfOperation::General, 10);

    expect(fn () => app(CreateCourse::class)->handle($actor, 'ODS', 'Impostor', AreaOfOperation::General, 20))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(Course::count())->toBe(1)
        ->and(AuditLogEntry::count())->toBe(1);
});

describe('the seeded catalog', function () {
    it('seeds every course through the audited command, ordered and grouped by area of operation', function () {
        User::factory()->admin()->create();

        $this->seed(CourseCatalogSeeder::class);

        $expected = count(CourseCatalogSeeder::catalog());
        expect(Course::count())->toBe($expected)
            ->and(AuditLogEntry::where('action', 'course.created')->count())->toBe($expected);

        $catalog = app(CourseRepository::class)->all();
        expect($catalog->pluck('code')->all())->toBe(array_column(CourseCatalogSeeder::catalog(), 'code'));
    });

    it('lists the courses tied to no single area of operation under General', function () {
        User::factory()->admin()->create();

        $this->seed(CourseCatalogSeeder::class);

        $general = Course::where('category', AreaOfOperation::General->value)->pluck('code');
        expect($general)->toContain('NCOA-1', 'NCOA-2', 'ODS');
    });

    it('is safe to run twice', function () {
        User::factory()->admin()->create();

        $this->seed(CourseCatalogSeeder::class);
        $this->seed(CourseCatalogSeeder::class);

        expect(Course::count())->toBe(count(CourseCatalogSeeder::catalog()));
    });

    it('has a group for every area of operation, so a new one cannot be forgotten', function () {
        // A group may be empty (no courses defined yet) but must exist, once, in the enum's order.
        $grouped = array_map(fn (array $group) => $group[0], CourseCatalogSeeder::groups());

        expect($grouped)->toBe(AreaOfOperation::cases());
    });

    it('has no duplicate codes', function () {
        $codes = array_column(CourseCatalogSeeder::catalog(), 'code');

        expect($codes)->toBe(array_values(array_unique($codes)));
    });

    it('refuses to seed without an Admin to audit the writes against', function () {
        User::factory()->create();

        expect(fn () => $this->seed(CourseCatalogSeeder::class))->toThrow(RuntimeException::class);

        expect(Course::count())->toBe(0);
    });
});
