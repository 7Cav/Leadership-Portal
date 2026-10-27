<?php

use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\CourseStatus;
use App\Models\Loa;
use App\Models\PortalRoleGrant;
use App\Models\RollcallSignature;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Models\TrooperCourse;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Stories 6.3 to 6.6: one trooper's milpac, the ID block, the courses panel, the awards history and the service-record timeline, gated by the ScopeResolver.
// Fixture tree: 7CAV ─ 1-7 ─ A/1-7 ─ 1/A ─┬─ 1/1/A (section, led by the viewer)
//                                          └─ 2/1/A
// "Now" is Thursday 2026-10-08.

function milpacTrooper(Unit $section, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create($attributes);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

function milpacOpen($test, Trooper $trooper)
{
    return $test->get(route('troopers.show', $trooper));
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));
    $units = app(UnitRepository::class);

    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $this->section1 = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);
    $this->section2 = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $platoon->id);

    UnitCommandMapping::factory()->create(['unit_id' => $this->section1->id, 'cav_position_id' => 501]);
    $leaderTrooper = milpacTrooper($this->section1, ['cav_position_id' => 501]);
    $this->leader = User::factory()->create(['cav_id' => $leaderTrooper->cav_id]);
});

describe('who may open it', function () {
    it('sends a signed-out visitor to sign in', function () {
        $trooper = milpacTrooper($this->section1);

        milpacOpen($this, $trooper)->assertRedirect(route('login'));
    });

    it('opens a trooper in the leader\'s own section', function () {
        $trooper = milpacTrooper($this->section1);

        milpacOpen($this->actingAs($this->leader), $trooper)->assertOk()->assertInertia(fn ($page) => $page->component('TrooperDetail'));
    });

    it('refuses a trooper outside the leader\'s scope, whatever link led there', function () {
        $outside = milpacTrooper($this->section2);

        milpacOpen($this->actingAs($this->leader), $outside)->assertNotFound();
    });

    it('opens a trooper in the union of every subtree the viewer holds', function () {
        $staff = User::factory()->serving()->create();
        PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $this->section1->id]);
        PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $this->section2->id]);

        milpacOpen($this->actingAs($staff), milpacTrooper($this->section1))->assertOk();
        milpacOpen($this, milpacTrooper($this->section2))->assertOk();
    });

    it('refuses someone with no scope at all', function () {
        milpacOpen($this->actingAs(User::factory()->serving()->create()), milpacTrooper($this->section1))->assertNotFound();
    });

    it('lets an Admin open any placed trooper', function () {
        $admin = User::factory()->admin()->create();

        milpacOpen($this->actingAs($admin), milpacTrooper($this->section2))->assertOk();
    });

    it('never widens a view-as preview past the previewed leader\'s scope (1.13, AD-9)', function () {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section1->id])->assertSessionHasNoErrors();

        milpacOpen($this, milpacTrooper($this->section1))->assertOk();
        milpacOpen($this, milpacTrooper($this->section2))->assertNotFound();
    });

    it('shows a trooper the sync has not placed to an Admin only', function () {
        $unplaced = Trooper::factory()->create();

        milpacOpen($this->actingAs($this->leader), $unplaced)->assertNotFound();
        milpacOpen($this->actingAs(User::factory()->admin()->create()), $unplaced)->assertOk();
    });

    it('answers a trooper that does not exist with 404', function () {
        $this->actingAs($this->leader)->get('/troopers/999999')->assertNotFound();
    });

    it('answers an id too long for an integer with 404 rather than erroring', function () {
        $this->actingAs($this->leader)->get('/troopers/99999999999999999999')->assertNotFound();
    });
});

describe('the ID block (6.3)', function () {
    it('shows rank, billet, MOS, time in service, time in grade and the primary section', function () {
        $trooper = milpacTrooper($this->section1, [
            'username' => 'Milkovic.D', 'rank' => 'Specialist', 'rank_short' => 'SPC', 'billet' => 'Assistant Section Leader',
            'mos' => '11B', 'tis' => '2025-01-14', 'tig' => '2026-04-08', 'status' => TrooperStatus::Combat,
        ]);

        milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page
            ->where('trooper.id', $trooper->id)
            ->where('trooper.cavId', $trooper->cav_id)
            ->where('trooper.displayName', 'SPC Milkovic.D')
            ->where('trooper.rank', 'Specialist')
            ->where('trooper.rankShort', 'SPC')
            ->where('trooper.billet', 'Assistant Section Leader')
            ->where('trooper.mos', '11B')
            ->where('trooper.status', 'combat')
            ->where('trooper.departed', false)
            ->where('trooper.tis', '2025-01-14')
            ->where('trooper.tisMonths', 20)
            ->where('trooper.tig', '2026-04-08')
            ->where('trooper.tigMonths', 6)
            ->where('trooper.section', ['id' => $this->section1->id, 'name' => '1st Section', 'callsign' => '1/1/A/1-7'])
            ->where('trooper.milpacUrl', 'https://7cav.us/rosters/profile?uniqueid='.$trooper->cav_id));
    });

    it('counts time on the server\'s UTC day, whole months only', function () {
        $trooper = milpacTrooper($this->section1, ['tis' => '2026-09-09', 'tig' => '2026-09-08']);

        milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page
            ->where('trooper.tisMonths', 0)
            ->where('trooper.tigMonths', 1));
    });

    it('leaves a duration unknown when its date is missing or in the future', function () {
        $trooper = milpacTrooper($this->section1, ['tis' => null, 'tig' => '2026-12-01']);

        milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page
            ->where('trooper.tis', null)
            ->where('trooper.tisMonths', null)
            ->where('trooper.tigMonths', null));
    });

    it('still renders a trooper with no billet, MOS or dates', function () {
        $trooper = milpacTrooper($this->section1, ['billet' => null, 'mos' => null, 'tis' => null, 'tig' => null, 'rank' => null, 'rank_short' => null, 'username' => null]);

        milpacOpen($this->actingAs($this->leader), $trooper)->assertOk()->assertInertia(fn ($page) => $page
            ->where('trooper.billet', null)
            ->where('trooper.mos', null)
            ->where('trooper.displayName', "#{$trooper->cav_id}"));
    });

    it('leaves a departed trooper\'s time in service and grade unknown rather than counting on to today', function () {
        $departed = milpacTrooper($this->section1, ['status' => TrooperStatus::Inactive, 'tis' => '2022-01-14', 'tig' => '2023-04-08']);

        milpacOpen($this->actingAs($this->leader), $departed)->assertInertia(fn ($page) => $page
            ->where('trooper.tis', '2022-01-14')
            ->where('trooper.tisMonths', null)
            ->where('trooper.tig', '2023-04-08')
            ->where('trooper.tigMonths', null));
    });

    it('names the page\'s breadcrumb crumb after the trooper', function () {
        $trooper = milpacTrooper($this->section1, ['username' => 'Milkovic.D', 'rank_short' => 'SPC']);

        milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page->where('crumb', 'SPC Milkovic.D'));
    });

    it('answers the same 404 for an out-of-scope trooper as for one that does not exist, on both routes', function () {
        $outside = milpacTrooper($this->section2);
        $this->actingAs($this->leader);

        $page = $this->get("/troopers/{$outside->id}")->assertNotFound();
        $missing = $this->get('/troopers/999999')->assertNotFound();
        $sheet = $this->getJson("/roster/troopers/{$outside->id}")->assertNotFound();

        expect($page->status())->toBe($missing->status())->toBe($sheet->status());
    });

    it('renders a departed trooper from their last cached identity, not a 404', function () {
        $departed = milpacTrooper($this->section1, ['status' => TrooperStatus::Inactive, 'username' => 'Gone.A', 'rank_short' => 'PFC']);

        milpacOpen($this->actingAs($this->leader), $departed)->assertOk()->assertInertia(fn ($page) => $page
            ->where('trooper.departed', true)
            ->where('trooper.status', 'inactive')
            ->where('trooper.displayName', 'PFC Gone.A'));
    });

    it('shows an unmapped trooper with no section, not an error', function () {
        $unplaced = Trooper::factory()->create(['rank_short' => 'PVT', 'username' => 'Fresh.B']);

        milpacOpen($this->actingAs(User::factory()->admin()->create()), $unplaced)->assertOk()->assertInertia(fn ($page) => $page
            ->where('trooper.section', null)
            ->where('trooper.displayName', 'PVT Fresh.B'));
    });

    it('is read-only: opening it writes nothing', function () {
        $trooper = milpacTrooper($this->section1);
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|truncate|alter|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        milpacOpen($this->actingAs($this->leader), $trooper)->assertOk();

        expect(array_filter($writes, fn (string $sql) => ! str_contains($sql, 'sessions')))->toBe([]);
    });
});

describe('where it came from (AD-20)', function () {
    it('names the roster source and its last successful sync', function () {
        SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Success, 'finished_at' => '2026-10-08 09:30:00']);

        milpacOpen($this->actingAs($this->leader), milpacTrooper($this->section1))->assertInertia(fn ($page) => $page
            ->where('sync.source', 'milpac')
            ->where('sync.lastSuccessAt', '2026-10-08T09:30:00+00:00')
            ->where('sync.stale', false)
            ->where('sync.lastAttempt', null));
    });

    it('shows the last good copy as stale when a later run failed', function () {
        SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Success, 'finished_at' => '2026-10-07 09:30:00']);
        SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Failure, 'finished_at' => '2026-10-08 09:30:00']);

        milpacOpen($this->actingAs($this->leader), milpacTrooper($this->section1))->assertInertia(fn ($page) => $page
            ->where('sync.lastSuccessAt', '2026-10-07T09:30:00+00:00')
            ->where('sync.stale', true)
            ->where('sync.lastAttempt.status', 'failure'));
    });

    it('says plainly when no sync has ever succeeded', function () {
        milpacOpen($this->actingAs($this->leader), milpacTrooper($this->section1))->assertInertia(fn ($page) => $page
            ->where('sync.lastSuccessAt', null)
            ->where('sync.stale', false));
    });

    it('does not mix up the LOA sync with the roster sync', function () {
        SyncRun::factory()->create(['source' => 'loa', 'status' => SyncRunStatus::Failure, 'finished_at' => '2026-10-08 09:30:00']);

        milpacOpen($this->actingAs($this->leader), milpacTrooper($this->section1))->assertInertia(fn ($page) => $page
            ->where('sync.lastSuccessAt', null)
            ->where('sync.stale', false));
    });
});

describe('the courses panel (6.4)', function () {
    function milpacCourse(Trooper $trooper, string $code, int $sort, CourseStatus $status, ?string $awardedAt = null): void
    {
        $course = Course::factory()->create(['code' => $code, 'name' => "Course {$code}", 'sort_order' => $sort]);
        TrooperCourse::factory()->create(['trooper_id' => $trooper->id, 'course_id' => $course->id, 'status' => $status, 'awarded_at' => $awardedAt]);
    }

    it('groups the trooper\'s courses as qualified, in progress and required, in that order', function () {
        $trooper = milpacTrooper($this->section1);
        milpacCourse($trooper, 'REQ', 10, CourseStatus::Required);
        milpacCourse($trooper, 'QUA', 20, CourseStatus::Qualified, '2025-02-02');
        milpacCourse($trooper, 'PRO', 30, CourseStatus::InProgress);

        milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page
            ->has('courses.groups', 3)
            ->where('courses.groups.0.status', 'qualified')
            ->where('courses.groups.0.label', 'Qualified')
            ->where('courses.groups.1.status', 'in_progress')
            ->where('courses.groups.2.status', 'required')
            ->where('courses.qualified', 1)
            ->where('courses.total', 3));
    });

    it('shows each course with its name and code, and a qualified one with its award date', function () {
        $trooper = milpacTrooper($this->section1);
        milpacCourse($trooper, 'BCT', 10, CourseStatus::Qualified, '2025-02-02');
        milpacCourse($trooper, 'NCOA', 20, CourseStatus::Qualified);

        milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page
            ->where('courses.groups.0.courses.0', ['code' => 'BCT', 'name' => 'Course BCT', 'awardedAt' => '2025-02-02'])
            ->where('courses.groups.0.courses.1', ['code' => 'NCOA', 'name' => 'Course NCOA', 'awardedAt' => null]));
    });

    it('orders courses within a group by the catalog\'s sort order', function () {
        $trooper = milpacTrooper($this->section1);
        milpacCourse($trooper, 'ZZZ', 10, CourseStatus::Qualified);
        milpacCourse($trooper, 'AAA', 30, CourseStatus::Qualified);
        milpacCourse($trooper, 'MMM', 20, CourseStatus::Qualified);

        milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page
            ->where('courses.groups.0.courses.0.code', 'ZZZ')
            ->where('courses.groups.0.courses.1.code', 'MMM')
            ->where('courses.groups.0.courses.2.code', 'AAA'));
    });

    it('leaves out none rows, and a group with nothing in it', function () {
        $trooper = milpacTrooper($this->section1);
        milpacCourse($trooper, 'ONE', 10, CourseStatus::Qualified);
        milpacCourse($trooper, 'NIL', 20, CourseStatus::None);

        milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page
            ->has('courses.groups', 1)
            ->where('courses.groups.0.status', 'qualified')
            ->has('courses.groups.0.courses', 1)
            ->where('courses.total', 1));
    });

    it('has no groups for a trooper with no courses on record', function () {
        milpacOpen($this->actingAs($this->leader), milpacTrooper($this->section1))->assertInertia(fn ($page) => $page
            ->where('courses.groups', [])
            ->where('courses.qualified', 0)
            ->where('courses.total', 0));
    });

    it('never shows another trooper\'s courses', function () {
        $trooper = milpacTrooper($this->section1);
        milpacCourse(milpacTrooper($this->section1), 'OTH', 10, CourseStatus::Qualified);

        milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page->where('courses.total', 0));
    });

    it('reads in a constant number of queries however many courses there are', function () {
        $trooper = milpacTrooper($this->section1);
        foreach (range(1, 12) as $i) {
            milpacCourse($trooper, sprintf('C%02d', $i), $i, CourseStatus::Qualified);
        }
        $this->actingAs($this->leader);

        DB::flushQueryLog();
        DB::enableQueryLog();
        milpacOpen($this, $trooper)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $one = milpacTrooper($this->section1);
        milpacCourse($one, 'SOLO', 100, CourseStatus::Qualified);
        DB::flushQueryLog();
        DB::enableQueryLog();
        milpacOpen($this, $one)->assertOk();
        $baseline = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($queries)->toBe($baseline);
    });
});

describe('the awards history (6.5)', function () {
    it('marks awards as not available, because the roster cache holds none to read', function () {
        $trooper = milpacTrooper($this->section1);

        milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page
            ->where('awards', ['available' => false, 'items' => []]));
    });

    it('adds no outbound call and no write to render it', function () {
        $trooper = milpacTrooper($this->section1);
        Http::fake();
        $before = DB::table('sync_runs')->count();

        milpacOpen($this->actingAs($this->leader), $trooper)->assertOk();

        Http::assertNothingSent();
        expect(DB::table('sync_runs')->count())->toBe($before);
    });
});

describe('the service-record timeline (6.6)', function () {
    function timelineCourse(Trooper $trooper, string $code, CourseStatus $status, ?string $awardedAt): TrooperCourse
    {
        $course = Course::factory()->create(['code' => $code, 'name' => "Course {$code}"]);

        return TrooperCourse::factory()->create(['trooper_id' => $trooper->id, 'course_id' => $course->id, 'status' => $status, 'awarded_at' => $awardedAt]);
    }

    it('shows the current rank with its date in grade and the dated qualified courses, newest first', function () {
        $trooper = milpacTrooper($this->section1, ['rank' => 'Specialist', 'tis' => '2025-01-14', 'tig' => '2026-04-08']);
        timelineCourse($trooper, 'BCT', CourseStatus::Qualified, '2025-02-02');
        timelineCourse($trooper, 'SAC', CourseStatus::Qualified, '2026-06-01');

        milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page
            ->where('timeline.entries', [
                ['type' => 'course', 'date' => '2026-06-01', 'title' => 'Course SAC', 'detail' => 'SAC'],
                ['type' => 'promotion', 'date' => '2026-04-08', 'title' => 'Current rank: Specialist', 'detail' => null],
                ['type' => 'course', 'date' => '2025-02-02', 'title' => 'Course BCT', 'detail' => 'BCT'],
            ])
            ->where('timeline.legend', [['type' => 'promotion', 'label' => 'Rank'], ['type' => 'course', 'label' => 'Course']]));
    });

    it('leaves out a type with no data, and its legend entry with it', function () {
        $trooper = milpacTrooper($this->section1, ['rank' => 'Specialist', 'tis' => '2025-01-14', 'tig' => '2026-04-08']);

        milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page
            ->has('timeline.entries', 1)
            ->where('timeline.legend', [['type' => 'promotion', 'label' => 'Rank']]));
    });

    it('does not place a course that has no date, or one that is not qualified', function () {
        $trooper = milpacTrooper($this->section1, ['tig' => null]);
        timelineCourse($trooper, 'UND', CourseStatus::Qualified, null);
        timelineCourse($trooper, 'PRO', CourseStatus::InProgress, '2026-01-01');
        timelineCourse($trooper, 'REQ', CourseStatus::Required, null);

        milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page
            ->where('timeline', ['entries' => [], 'legend' => []]));
    });

    it('shows no promotion for a trooper whose grade date is the day they joined', function () {
        $trooper = milpacTrooper($this->section1, ['rank' => 'Private', 'tis' => '2026-02-02', 'tig' => '2026-02-02']);

        milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page->where('timeline.entries', []));
    });

    it('shows no promotion for a grade date in the future, as the ID block reads it as unknown', function () {
        $trooper = milpacTrooper($this->section1, ['rank' => 'Specialist', 'tis' => '2025-01-14', 'tig' => '2026-12-01']);

        milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page
            ->where('trooper.tigMonths', null)
            ->where('timeline.entries', []));
    });

    it('shows no promotion when the rank or the grade date is not known', function () {
        $noRank = milpacTrooper($this->section1, ['rank' => null, 'tig' => '2026-04-08']);
        $noDate = milpacTrooper($this->section1, ['rank' => 'Specialist', 'tig' => null]);

        foreach ([$noRank, $noDate] as $trooper) {
            milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page->where('timeline.entries', []));
        }
    });

    it('orders two events on the same day by type, then by the row, the same on every load', function () {
        $trooper = milpacTrooper($this->section1, ['rank' => 'Specialist', 'tis' => '2025-01-14', 'tig' => '2026-04-08']);
        $first = timelineCourse($trooper, 'AAA', CourseStatus::Qualified, '2026-04-08');
        $second = timelineCourse($trooper, 'BBB', CourseStatus::Qualified, '2026-04-08');
        $titles = fn () => collect(milpacOpen($this->actingAs($this->leader), $trooper)->viewData('page')['props']['timeline']['entries'])->pluck('title')->all();

        expect($first->id)->toBeLessThan($second->id)
            ->and($titles())->toBe(['Current rank: Specialist', 'Course AAA', 'Course BBB'])
            ->and($titles())->toBe(['Current rank: Specialist', 'Course AAA', 'Course BBB']);
    });

    it('never shows another trooper\'s events', function () {
        $trooper = milpacTrooper($this->section1, ['tig' => null]);
        $other = milpacTrooper($this->section1, ['tig' => null]);
        timelineCourse($other, 'OTH', CourseStatus::Qualified, '2026-01-01');

        milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page->where('timeline.entries', []));
    });

    it('reads in a constant number of queries however many events there are', function () {
        $trooper = milpacTrooper($this->section1, ['tig' => null]);
        $count = function () use ($trooper) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            milpacOpen($this->actingAs($this->leader), $trooper)->assertOk();

            return count(DB::getQueryLog());
        };

        timelineCourse($trooper, 'C01', CourseStatus::Qualified, '2026-01-01');
        $few = $count();

        foreach (range(2, 12) as $n) {
            timelineCourse($trooper, sprintf('C%02d', $n), CourseStatus::Qualified, '2026-01-0'.min($n, 9));
        }

        expect($count())->toBe($few);
    });
});

describe('the leadership quick actions (6.7)', function () {
    function milpacActions($test, Trooper $trooper): array
    {
        return milpacOpen($test, $trooper)->viewData('page')['props']['actions'];
    }

    it('offers the section roster and rollcall to a viewer who holds the trooper\'s section', function () {
        $trooper = milpacTrooper($this->section1);

        milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page
            ->where('actions', [
                ['key' => 'section-roster', 'label' => 'Open section roster', 'href' => "/roster/{$this->section1->id}"],
                ['key' => 'rollcall', 'label' => 'Jump to rollcall', 'href' => "/rollcall/{$this->section1->id}?focus={$trooper->id}"],
            ]));
    });

    it('jumps to this week\'s rollcall of the trooper\'s section, which lists the trooper it focuses', function () {
        $trooper = milpacTrooper($this->section1);
        $href = collect(milpacActions($this->actingAs($this->leader), $trooper))->firstWhere('key', 'rollcall')['href'];

        $this->get($href)->assertOk()->assertInertia(fn ($page) => $page
            ->component('Rollcall')
            ->where('section.id', $this->section1->id)
            ->where('week.isCurrent', true)
            ->where('troopers', fn ($troopers) => collect($troopers)->pluck('id')->contains($trooper->id)));
    });

    it('opens the rollcall plainly for a departed trooper, who has no card on it', function () {
        $departed = milpacTrooper($this->section1, ['status' => TrooperStatus::Inactive]);

        expect(collect(milpacActions($this->actingAs($this->leader), $departed))->firstWhere('key', 'rollcall'))
            ->toBe(['key' => 'rollcall', 'label' => 'Open in rollcall', 'href' => "/rollcall/{$this->section1->id}"]);
    });

    it('hides the platoon readiness matrix from a section leader, whose scope does not hold the platoon', function () {
        expect(collect(milpacActions($this->actingAs($this->leader), milpacTrooper($this->section1)))->pluck('key')->all())
            ->toBe(['section-roster', 'rollcall']);
    });

    it('links the platoon readiness matrix for a viewer who holds the platoon', function () {
        $platoonId = $this->section1->parent_id;
        $staff = User::factory()->serving()->create();
        PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $platoonId]);

        milpacOpen($this->actingAs($staff), milpacTrooper($this->section2))->assertInertia(fn ($page) => $page
            ->where('actions.2', ['key' => 'readiness-matrix', 'label' => 'View platoon readiness matrix', 'href' => "/readiness/{$platoonId}"]));
    });

    it('offers an Admin the same section actions, and none for a trooper the sync has not placed', function () {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        expect(collect(milpacActions($this, milpacTrooper($this->section1)))->pluck('key')->all())->toBe(['section-roster', 'rollcall', 'readiness-matrix'])
            ->and(milpacActions($this, Trooper::factory()->create()))->toBe([]);
    });

    it('follows a view-as preview: previewing a leader of another section removes the actions', function () {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section2->id])->assertSessionHasNoErrors();

        // The trooper is then out of the previewed scope: the page itself answers 404, so no action is ever offered.
        milpacOpen($this, milpacTrooper($this->section1))->assertNotFound();
    });

    it('is not the security boundary: the roster of a section outside scope is refused when its URL is typed by hand', function () {
        $this->actingAs($this->leader)->get(route('roster.index', $this->section2->id))->assertForbidden();
    });

    it('offers only plain GET links to existing routes, so no action can change a trooper', function () {
        $admin = User::factory()->admin()->create();

        foreach (milpacActions($this->actingAs($admin), milpacTrooper($this->section1)) as $action) {
            $route = app('router')->getRoutes()->match(Request::create($action['href'], 'GET'));

            expect($route->methods())->toContain('GET')->not->toContain('POST')->not->toContain('PUT')->not->toContain('DELETE');
        }
    });
});

describe('the makeup PM (Command Brief principle 2)', function () {
    // Marks the trooper's SP for the Cav week `$back` weeks before the current one (which starts Saturday 2026-10-03).
    function milpacMark(Trooper $trooper, Unit $section, int $back, AttendanceStatus $status): void
    {
        $practice = app(SectionPracticeRepository::class)->findOrCreate($section->id, CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC')->subWeeks($back));
        app(AttendanceRepository::class)->markPractice($practice, $trooper->id, $status, AttendanceSource::Manual);
    }

    it('gives the page the forum username and the missed weeks of a trooper in the viewer\'s section', function () {
        $trooper = milpacTrooper($this->section1, ['username' => 'Reyes.M']);
        milpacMark($trooper, $this->section1, 2, AttendanceStatus::Missed);
        milpacMark($trooper, $this->section1, 1, AttendanceStatus::Present);
        milpacMark($trooper, $this->section1, 0, AttendanceStatus::Missed);

        milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page
            ->where('makeup', ['username' => 'Reyes.M', 'missedWeeks' => ['2026-09-19', '2026-10-03']]));
    });

    it('offers none for a trooper who missed nothing in the window', function () {
        $trooper = milpacTrooper($this->section1, ['username' => 'Reyes.M']);
        milpacMark($trooper, $this->section1, 0, AttendanceStatus::Present);
        milpacMark($trooper, $this->section1, 4, AttendanceStatus::Missed);

        milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page->where('makeup', null));
    });

    it('offers none for a trooper with no forum username to address', function () {
        $trooper = milpacTrooper($this->section1, ['username' => null]);
        milpacMark($trooper, $this->section1, 0, AttendanceStatus::Missed);

        milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page->where('makeup', null));
    });

    it('offers none for a trooper the sync has not placed', function () {
        $this->actingAs(User::factory()->admin()->create());

        milpacOpen($this, Trooper::factory()->create(['username' => 'Reyes.M']))->assertInertia(fn ($page) => $page->where('makeup', null));
    });
});

// Decided by the product owner 2026-10-27: "When you transfer to a new area, the new leadership can see your history,
// it's linked to your profile." Anyone who may see the trooper now sees their signing, practice marks and LOAs from
// before the move, week by week with the section, and the move itself.
describe('attendance across transfers', function () {
    it('shows the weeks at the old section to the new leadership, each with its section, and the move', function () {
        $trooper = milpacTrooper($this->section2);
        $old = app(SectionPracticeRepository::class)->findOrCreate($this->section2->id, CarbonImmutable::parse('2026-09-19 00:00:00', 'UTC'));
        app(AttendanceRepository::class)->markPractice($old, $trooper->id, AttendanceStatus::Present, AttendanceSource::Manual);
        app(AttendanceRepository::class)->signRollcall($old, $trooper->id, RollcallSignature::WillAttend);
        $this->travelTo(CarbonImmutable::parse('2026-09-30 18:00:00', 'UTC'));
        app(TrooperRepository::class)->updatePrimarySections([$trooper->cav_id => $this->section1->id]);
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));
        Loa::factory()->create(['trooper_id' => $trooper->id, 'starts_at' => '2026-09-12', 'ends_at' => '2026-09-14']);

        milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page
            ->where('transfers', [['at' => '2026-09-30T18:00:00Z', 'from' => '2/1/A/1-7', 'to' => '1/1/A/1-7']])
            ->has('attendance', 20)
            ->where('attendance.19.week', '2026-10-03')
            ->where('attendance.19.section', ['id' => $this->section1->id, 'label' => '1/1/A/1-7'])
            ->where('attendance.17.week', '2026-09-19')
            ->where('attendance.17.section', ['id' => $this->section2->id, 'label' => '2/1/A/1-7'])
            ->where('attendance.17.status', 'present')
            ->where('attendance.17.rollcall', 'will_attend')
            ->where('attendance.16.week', '2026-09-12')
            ->where('attendance.16.loa', true)
            ->where('attendance.16.status', null));
    });

    it('shows no transfers and the current section for a trooper who never moved', function () {
        $trooper = milpacTrooper($this->section1);

        milpacOpen($this->actingAs($this->leader), $trooper)->assertInertia(fn ($page) => $page
            ->where('transfers', [])
            ->where('attendance.0.section.id', $this->section1->id)
            ->where('attendance.0.status', null));
    });
});
