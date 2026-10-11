<?php

use App\Models\Course;
use App\Models\CourseStatus;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\TrooperCourse;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Illuminate\Support\Facades\DB;

// Story 6.9: the training-readiness matrix of one unit, gated by the ScopeResolver.
// Fixture tree: 7CAV ─ 1-7 ─ A/1-7 ─ 1/A ─┬─ 1/1/A (section, led by the viewer)
//                                          ├─ 2/1/A
//                                          └─ 2/A (another platoon) ─ 1/2/A

function readinessTrooper(Unit $section, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

function readinessCourse(string $code, int $sort): Course
{
    return Course::factory()->create(['code' => $code, 'name' => "Course {$code}", 'sort_order' => $sort]);
}

function readinessSet(Trooper $trooper, Course $course, CourseStatus $status): void
{
    TrooperCourse::factory()->create(['trooper_id' => $trooper->id, 'course_id' => $course->id, 'status' => $status]);
}

function readinessOpen($test, Unit $unit)
{
    return $test->get(route('readiness.matrix', $unit->id));
}

beforeEach(function () {
    $units = app(UnitRepository::class);

    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
    $this->platoon1 = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $this->platoon2 = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $company->id);
    $this->section1 = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon1->id);
    $this->section2 = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->platoon1->id);
    $this->section3 = $units->create(UnitType::Section, '1st Section, 2nd Platoon', '1/2/A/1-7', $this->platoon2->id);

    UnitCommandMapping::factory()->create(['unit_id' => $this->section1->id, 'cav_position_id' => 501]);
    $leaderTrooper = readinessTrooper($this->section1, ['cav_position_id' => 501, 'username' => 'Lead.Er', 'rank_short' => 'PVT']);
    $this->leader = User::factory()->create(['cav_id' => $leaderTrooper->cav_id]);
    $this->platoonStaff = User::factory()->create();
    PortalRoleGrant::factory()->create(['user_id' => $this->platoonStaff->id, 'unit_id' => $this->platoon1->id]);
});

describe('who may open it', function () {
    it('sends a signed-out visitor to sign in', function () {
        readinessOpen($this, $this->section1)->assertRedirect(route('login'));
    });

    it('opens the viewer\'s own section', function () {
        readinessOpen($this->actingAs($this->leader), $this->section1)->assertOk()
            ->assertInertia(fn ($page) => $page->component('TrainingReadinessMatrix')->where('unit.id', $this->section1->id));
    });

    it('refuses a platoon above the viewer\'s scope, whatever link led there', function () {
        readinessOpen($this->actingAs($this->leader), $this->platoon1)->assertForbidden();
    });

    it('refuses a unit in another command when its URL is typed by hand', function () {
        readinessOpen($this->actingAs($this->platoonStaff), $this->platoon2)->assertForbidden();
        readinessOpen($this, $this->section3)->assertForbidden();
    });

    it('opens the platoon for someone who holds it, and every section below it', function () {
        readinessOpen($this->actingAs($this->platoonStaff), $this->platoon1)->assertOk();
        readinessOpen($this, $this->section2)->assertOk();
    });

    it('refuses someone with no scope at all', function () {
        readinessOpen($this->actingAs(User::factory()->create()), $this->section1)->assertForbidden();
    });

    it('lets an Admin open any unit', function () {
        $admin = User::factory()->admin()->create();

        readinessOpen($this->actingAs($admin), $this->platoon2)->assertOk();
    });

    it('never widens a view-as preview past the previewed leader\'s scope (1.13, AD-9)', function () {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section1->id])->assertSessionHasNoErrors();

        readinessOpen($this, $this->section1)->assertOk();
        readinessOpen($this, $this->platoon1)->assertForbidden();
    });

    it('answers a unit that does not exist with 404', function () {
        $this->actingAs($this->leader)->get('/readiness/999999')->assertNotFound();
    });

    it('answers a unit id too long for an integer with 404 rather than erroring', function () {
        $this->actingAs($this->leader)->get('/readiness/99999999999999999999')->assertNotFound();
    });
});

describe('the grid', function () {
    it('has a column for every course in the catalog, in catalog order, even one nobody holds or needs', function () {
        readinessCourse('LATE', 30);
        readinessCourse('TIE-B', 10);
        readinessCourse('TIE-A', 10);
        readinessCourse('NOBODY', 20);
        readinessTrooper($this->section1);
        $existing = Course::query()->orderBy('sort_order')->orderBy('code')->pluck('code')->all();

        readinessOpen($this->actingAs($this->leader), $this->section1)->assertInertia(fn ($page) => $page
            ->has('courses', count($existing))
            ->where('courses', fn ($courses) => collect($courses)->pluck('code')->all() === $existing)
            ->where('coverage', fn ($coverage) => collect($coverage)->every(fn ($entry) => $entry['percent'] === 0)));
    });

    it('shows each trooper\'s status per course, and none where they have no row', function () {
        $qualified = readinessCourse('QUA', 10);
        $progress = readinessCourse('PRO', 20);
        $required = readinessCourse('REQ', 30);
        $absent = readinessCourse('ABS', 40);
        $stored = readinessCourse('NON', 50);
        $trooper = readinessTrooper($this->section1);
        readinessSet($trooper, $qualified, CourseStatus::Qualified);
        readinessSet($trooper, $progress, CourseStatus::InProgress);
        readinessSet($trooper, $required, CourseStatus::Required);
        readinessSet($trooper, $stored, CourseStatus::None);

        readinessOpen($this->actingAs($this->leader), $this->section1)->assertInertia(fn ($page) => $page
            ->where('troopers', function ($troopers) use ($trooper, $qualified, $progress, $required, $absent, $stored) {
                $row = collect($troopers)->firstWhere('id', $trooper->id);

                return $row['statuses'][$qualified->id] === 'qualified'
                    && $row['statuses'][$progress->id] === 'in_progress'
                    && $row['statuses'][$required->id] === 'required'
                    && $row['statuses'][$absent->id] === 'none'
                    && $row['statuses'][$stored->id] === 'none';
            }));
    });

    it('lists the active troopers anywhere under the unit, and nobody else', function () {
        readinessTrooper($this->section1, ['username' => 'In.Section', 'rank_short' => 'PVT']);
        readinessTrooper($this->section2, ['username' => 'In.Sibling', 'rank_short' => 'PVT']);
        readinessTrooper($this->section2, ['username' => 'Gone.Away', 'rank_short' => 'PVT', 'status' => TrooperStatus::Inactive]);
        readinessTrooper($this->section3, ['username' => 'Other.Platoon', 'rank_short' => 'PVT']);
        Trooper::factory()->create(['username' => 'Not.Placed', 'rank_short' => 'PVT']);

        $names = fn ($unit, $test) => collect(readinessOpen($test, $unit)->viewData('page')['props']['troopers'])->pluck('displayName')->sort()->values()->all();

        expect($names($this->platoon1, $this->actingAs($this->platoonStaff)))->toBe(['PVT In.Section', 'PVT In.Sibling', 'PVT Lead.Er'])
            ->and($names($this->section2, $this))->toBe(['PVT In.Sibling']);
    });

    it('keeps Reserve and ELOA troopers, which are still serving', function () {
        readinessTrooper($this->section2, ['username' => 'Res.One', 'rank_short' => 'PVT', 'status' => TrooperStatus::Reserve]);
        readinessTrooper($this->section2, ['username' => 'Eloa.One', 'rank_short' => 'PVT', 'status' => TrooperStatus::Eloa]);

        readinessOpen($this->actingAs($this->platoonStaff), $this->section2)
            ->assertInertia(fn ($page) => $page->has('troopers', 2));
    });

    it('never shows a trooper twice, or another unit\'s course marks', function () {
        $course = readinessCourse('QUA', 10);
        $mine = readinessTrooper($this->section2);
        $other = readinessTrooper($this->section3);
        readinessSet($other, $course, CourseStatus::Qualified);

        readinessOpen($this->actingAs($this->platoonStaff), $this->section2)->assertInertia(fn ($page) => $page
            ->has('troopers', 1)
            ->where('troopers.0.id', $mine->id)
            ->where('troopers.0.statuses.'.$course->id, 'none'));
    });
});

describe('coverage', function () {
    function readinessSection2(int $count, int $qualified, Course $course): void
    {
        foreach (range(1, $count) as $n) {
            $trooper = readinessTrooper(test()->section2);
            if ($n <= $qualified) {
                readinessSet($trooper, $course, CourseStatus::Qualified);
            }
        }
    }

    it('is qualified over every trooper shown, rounded to a whole percent', function () {
        $course = readinessCourse('QUA', 10);
        readinessSection2(3, 1, $course);

        readinessOpen($this->actingAs($this->platoonStaff), $this->section2)->assertInertia(fn ($page) => $page
            ->where('coverage', fn ($coverage) => collect($coverage)->firstWhere('courseId', $course->id) === ['courseId' => $course->id, 'qualified' => 1, 'total' => 3, 'percent' => 33]));
    });

    it('rounds up from a half', function () {
        $course = readinessCourse('QUA', 10);
        readinessSection2(3, 2, $course);

        readinessOpen($this->actingAs($this->platoonStaff), $this->section2)->assertInertia(fn ($page) => $page
            ->where('coverage', fn ($coverage) => collect($coverage)->firstWhere('courseId', $course->id)['percent'] === 67));
    });

    it('does not count in progress or required as covered', function () {
        $course = readinessCourse('QUA', 10);
        readinessSet(readinessTrooper($this->section2), $course, CourseStatus::InProgress);
        readinessSet(readinessTrooper($this->section2), $course, CourseStatus::Required);
        readinessSet(readinessTrooper($this->section2), $course, CourseStatus::Qualified);
        readinessTrooper($this->section2);

        readinessOpen($this->actingAs($this->platoonStaff), $this->section2)->assertInertia(fn ($page) => $page
            ->where('coverage', fn ($coverage) => collect($coverage)->firstWhere('courseId', $course->id)['percent'] === 25));
    });

    it('is a plain ratio: it reads no KPI and writes nothing', function () {
        $course = readinessCourse('QUA', 10);
        readinessSection2(2, 2, $course);
        $courseRows = DB::table('trooper_courses')->count();
        $audit = DB::table('audit_log')->count();

        readinessOpen($this->actingAs($this->platoonStaff), $this->section2)->assertOk();

        expect(DB::table('trooper_courses')->count())->toBe($courseRows)
            ->and(DB::table('audit_log')->count())->toBe($audit);
    });
});

describe('an empty unit', function () {
    it('keeps every course column and reports no coverage rather than 0 of 0', function () {
        $course = readinessCourse('QUA', 10);
        $before = Course::query()->count();

        readinessOpen($this->actingAs($this->platoonStaff), $this->section2)->assertOk()->assertInertia(fn ($page) => $page
            ->has('troopers', 0)
            ->has('courses', $before)
            ->where('coverage', fn ($coverage) => collect($coverage)->firstWhere('courseId', $course->id)['percent'] === null));
    });
});

describe('the milpac deep link (6.7)', function () {
    it('lands on the matrix of the trooper\'s platoon', function () {
        $trooper = readinessTrooper($this->section2);
        $href = collect(
            $this->actingAs($this->platoonStaff)->get(route('troopers.show', $trooper))->viewData('page')['props']['actions'],
        )->firstWhere('key', 'readiness-matrix')['href'];

        expect($href)->toBe("/readiness/{$this->platoon1->id}");

        $this->get($href)->assertOk()->assertInertia(fn ($page) => $page->where('unit.id', $this->platoon1->id)->where('unit.type', 'platoon'));
    });
});

it('reads in a constant number of queries however many troopers and courses there are', function () {
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        readinessOpen($this->actingAs($this->platoonStaff), $this->platoon1)->assertOk();

        return count(DB::getQueryLog());
    };

    $first = readinessCourse('C01', 1);
    readinessSet(readinessTrooper($this->section2), $first, CourseStatus::Qualified);
    $few = $count();

    foreach (range(2, 8) as $n) {
        $course = readinessCourse(sprintf('C%02d', $n), $n);
        foreach (range(1, 3) as $m) {
            readinessSet(readinessTrooper($this->section2), $course, CourseStatus::Qualified);
        }
    }

    expect($count())->toBe($few);
});
