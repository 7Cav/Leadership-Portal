<?php

use App\Models\Course;
use App\Models\CourseStatus;
use App\Models\PortalRoleGrant;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Models\TrooperCourse;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Stories 6.3 to 6.5: one trooper's milpac, the ID block, the courses panel and the awards history, gated by the ScopeResolver.
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
        milpacOpen($this->actingAs(User::factory()->create()), milpacTrooper($this->section1))->assertNotFound();
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
    it('names the Cav roster and its last successful sync', function () {
        SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Success, 'finished_at' => '2026-10-08 09:30:00']);

        milpacOpen($this->actingAs($this->leader), milpacTrooper($this->section1))->assertInertia(fn ($page) => $page
            ->where('sync.source', 'Cav roster')
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
