<?php

use App\Models\ArtifactType;
use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\Loa;
use App\Models\PortalRoleGrant;
use App\Models\RollcallSignature;
use App\Models\SectionPractice;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TemplateVersionRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Story 3.7: the weekly rollcall of one section, scope-gated by the ScopeResolver and pre-filled from the roster, the
// leave data and the prior week. "Now" is Thursday 2026-10-08, so the current Cav week opens Saturday 2026-10-03 and the
// week before opens 2026-09-26.
// Fixture tree: 7CAV ─ 1-7 ─ A/1-7 ─ 1/A ─┬─ 1/1/A (section, led by the viewer)
//                                          └─ 2/1/A

const ROLLCALL_WEEK = '2026-10-03';
const ROLLCALL_PRIOR_WEEK = '2026-09-26';

function rollcallTrooper(Unit $section, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create(['rank_short' => 'PFC', ...$attributes]);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

function rollcallMark(Unit $section, string $week, Trooper $trooper, ?AttendanceStatus $status, array $attributes = []): Attendance
{
    $practice = SectionPractice::query()->firstOrCreate(['section_id' => $section->id, 'week_start' => CarbonImmutable::parse($week, 'UTC')]);

    return Attendance::factory()->create([
        'trooper_id' => $trooper->id,
        'section_practice_id' => $practice->id,
        'status' => $status,
        'source' => $status === null ? null : AttendanceSource::Manual,
        'rollcall' => RollcallSignature::Unsigned,
        ...$attributes,
    ]);
}

function rollcallOpen($test, Unit $section, string $query = '')
{
    return $test->get(route('rollcall.show', $section->id).$query);
}

function rollcallRow($test, Unit $section, Trooper $trooper, string $query = ''): array
{
    return collect(rollcallOpen($test, $section, $query)->viewData('page')['props']['troopers'])->firstWhere('id', $trooper->id);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));
    $units = app(UnitRepository::class);

    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $this->section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->sibling = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->platoon->id);

    $this->leaderTrooper = rollcallTrooper($this->section, ['cav_position_id' => 501, 'username' => 'Lead.Er']);
    UnitCommandMapping::factory()->create(['unit_id' => $this->section->id, 'cav_position_id' => 501]);
    $this->leader = User::factory()->create(['cav_id' => $this->leaderTrooper->cav_id]);

    $this->staff = User::factory()->create();
    PortalRoleGrant::factory()->create(['user_id' => $this->staff->id, 'unit_id' => $this->platoon->id]);
});

describe('who may open it', function () {
    it('sends a signed-out visitor to sign in', function () {
        rollcallOpen($this, $this->section)->assertRedirect(route('login'));
    });

    it('opens the viewer\'s own section', function () {
        rollcallOpen($this->actingAs($this->leader), $this->section)->assertOk()
            ->assertInertia(fn ($page) => $page->component('Rollcall')->where('section.id', $this->section->id));
    });

    it('refuses a section outside the viewer\'s scope, whatever link led there', function () {
        rollcallOpen($this->actingAs($this->leader), $this->sibling)->assertForbidden();
    });

    it('opens every section below a platoon for someone who holds it', function () {
        rollcallOpen($this->actingAs($this->staff), $this->section)->assertOk();
        rollcallOpen($this, $this->sibling)->assertOk();
    });

    it('refuses someone with no scope at all', function () {
        rollcallOpen($this->actingAs(User::factory()->create()), $this->section)->assertForbidden();
    });

    it('lets an Admin open any section', function () {
        rollcallOpen($this->actingAs(User::factory()->admin()->create()), $this->sibling)->assertOk();
    });

    it('answers a unit that is not a section with 404, since a rollcall is kept per section', function () {
        $this->actingAs($this->staff)->get(route('rollcall.show', $this->platoon->id))->assertNotFound();
    });

    it('answers an id too long for any unit with 404, not an integer overflow', function () {
        // Twenty digits is past the signed 64-bit range: under whereNumber it reached the binding and failed there (a 500).
        $this->actingAs($this->staff)->get('/rollcall/12345678901234567890')->assertNotFound();
        $this->get('/rollcall/'.str_repeat('9', 19))->assertNotFound();
    });

    it('never widens a view-as preview past the previewed leader\'s scope (1.13, AD-9)', function () {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section->id])->assertSessionHasNoErrors();

        rollcallOpen($this, $this->section)->assertOk();
        rollcallOpen($this, $this->sibling)->assertForbidden();
    });

    it('opens the first section in scope from the nav entry', function () {
        $this->actingAs($this->leader)->get(route('rollcall.index'))->assertRedirect(route('rollcall.show', $this->section->id));
    });

    it('shows an empty state at the nav entry for someone who holds no section', function () {
        $this->actingAs(User::factory()->create())->get(route('rollcall.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Rollcall')->where('section', null)->where('sections', []));
    });

    it('lists the sections in the viewer\'s scope for the picker, and only those', function () {
        rollcallOpen($this->actingAs($this->staff), $this->section)->assertInertia(fn ($page) => $page
            ->has('sections', 2)
            ->where('sections.0.id', $this->section->id));

        rollcallOpen($this->actingAs($this->leader), $this->section)->assertInertia(fn ($page) => $page->has('sections', 1));
    });
});

describe('the week', function () {
    it('opens the current Cav week by default, with no later week to go to', function () {
        rollcallOpen($this->actingAs($this->leader), $this->section)->assertInertia(fn ($page) => $page
            ->where('week.start', ROLLCALL_WEEK)
            ->where('week.end', '2026-10-09')
            ->where('week.isCurrent', true)
            ->where('week.previous', ROLLCALL_PRIOR_WEEK)
            ->where('week.next', null));
    });

    it('takes any day in a week as that week', function () {
        rollcallOpen($this->actingAs($this->leader), $this->section, '?week=2026-09-30')->assertInertia(fn ($page) => $page
            ->where('week.start', ROLLCALL_PRIOR_WEEK)
            ->where('week.isCurrent', false)
            ->where('week.next', ROLLCALL_WEEK));
    });

    it('opens an earlier week, with the week before it and the one after', function () {
        rollcallOpen($this->actingAs($this->leader), $this->section, '?week=2026-09-12')->assertInertia(fn ($page) => $page
            ->where('week.start', '2026-09-12')
            ->where('week.previous', '2026-09-05')
            ->where('week.next', '2026-09-19'));
    });

    it('drops a week that is not a date, and a week still to come, back to the current one', function (string $query) {
        rollcallOpen($this->actingAs($this->leader), $this->section, $query)->assertRedirect(route('rollcall.show', $this->section->id));
    })->with([
        'not a date' => ['?week=banana'],
        'next week' => ['?week=2026-10-10'],
        'far future' => ['?week=2030-01-01'],
        // An array is not a week either: it was a PHP "Array to string conversion" error (a 500), not a fallback.
        'an array' => ['?week[]=2026-09-26'],
        'a keyed array' => ['?week[start]=2026-09-26'],
    ]);

    it('counts the last Friday of a week as that week and the next Saturday as the next', function () {
        rollcallOpen($this->actingAs($this->leader), $this->section, '?week=2026-09-25')->assertInertia(fn ($page) => $page->where('week.start', '2026-09-19'));
        rollcallOpen($this->actingAs($this->leader), $this->section, '?week=2026-09-26')->assertInertia(fn ($page) => $page->where('week.start', '2026-09-26'));
    });
});

describe('the roster', function () {
    it('lists the active troopers whose primary section it is, and nobody else', function () {
        rollcallTrooper($this->section, ['username' => 'In.Section']);
        rollcallTrooper($this->sibling, ['username' => 'In.Sibling']);
        rollcallTrooper($this->section, ['username' => 'Gone.Away', 'status' => TrooperStatus::Inactive]);
        Trooper::factory()->create(['username' => 'Not.Placed']);

        $names = collect(rollcallOpen($this->actingAs($this->leader), $this->section)->viewData('page')['props']['troopers'])->pluck('displayName')->sort()->values()->all();

        expect($names)->toBe(['PFC In.Section', 'PFC Lead.Er']);
    });

    it('keeps Reserve and ELOA troopers, who still belong to the section', function () {
        rollcallTrooper($this->section, ['username' => 'Res.One', 'status' => TrooperStatus::Reserve]);
        rollcallTrooper($this->section, ['username' => 'Eloa.One', 'status' => TrooperStatus::Eloa]);

        rollcallOpen($this->actingAs($this->leader), $this->section)->assertInertia(fn ($page) => $page->has('troopers', 3));
    });

    it('lists a trooper with a billet in another section exactly once, in their primary section', function () {
        $dual = rollcallTrooper($this->sibling, ['username' => 'Dual.Hat', 'cav_position_id' => 501]);

        $names = fn (Unit $section) => collect(rollcallOpen($this->actingAs($this->staff), $section)->viewData('page')['props']['troopers'])->pluck('id');

        expect($names($this->sibling))->toContain($dual->id)
            ->and($names($this->section))->not->toContain($dual->id);
    });

    it('renders an empty section as an empty roster, not an error', function () {
        $this->leaderTrooper->forceFill(['primary_section_id' => $this->sibling->id])->save();

        rollcallOpen($this->actingAs($this->staff), $this->section)->assertOk()->assertInertia(fn ($page) => $page
            ->where('troopers', [])
            ->where('banner.eligible', 0)
            ->where('banner.carried', 0));
    });

    it('carries each trooper\'s identity, rank, billet and roster standing', function () {
        $trooper = rollcallTrooper($this->section, ['username' => 'Hollis.D', 'rank_short' => 'SPC', 'billet' => 'Rifleman']);

        expect(rollcallRow($this->actingAs($this->leader), $this->section, $trooper))->toMatchArray([
            'id' => $trooper->id,
            'displayName' => 'SPC Hollis.D',
            'rankShort' => 'SPC',
            'billet' => 'Rifleman',
            'status' => 'combat',
        ]);
    });
});

describe('the practice value of a row', function () {
    it('loads unmarked when nothing is saved and nothing carries over', function () {
        $trooper = rollcallTrooper($this->section);

        expect(rollcallRow($this->actingAs($this->leader), $this->section, $trooper)['practice'])->toBe(['value' => null, 'origin' => null, 'source' => null]);
    });

    it('shows the mark already saved for the week, with who made it', function () {
        $trooper = rollcallTrooper($this->section);
        rollcallMark($this->section, ROLLCALL_WEEK, $trooper, AttendanceStatus::Makeup, ['source' => AttendanceSource::Auto]);

        expect(rollcallRow($this->actingAs($this->leader), $this->section, $trooper)['practice'])->toBe(['value' => 'makeup', 'origin' => 'saved', 'source' => 'auto']);
    });

    it('carries present over from the prior week as a suggestion, not a stored mark', function () {
        $trooper = rollcallTrooper($this->section);
        rollcallMark($this->section, ROLLCALL_PRIOR_WEEK, $trooper, AttendanceStatus::Present);

        expect(rollcallRow($this->actingAs($this->leader), $this->section, $trooper)['practice'])->toBe(['value' => 'present', 'origin' => 'carried', 'source' => null]);
        expect(Attendance::query()->where('trooper_id', $trooper->id)->count())->toBe(1, 'viewing stored nothing');
    });

    it('never carries missed, makeup or attached over, so nobody starts the week marked absent', function (AttendanceStatus $status) {
        $trooper = rollcallTrooper($this->section);
        rollcallMark($this->section, ROLLCALL_PRIOR_WEEK, $trooper, $status);

        expect(rollcallRow($this->actingAs($this->leader), $this->section, $trooper)['practice']['value'])->toBeNull();
    })->with([AttendanceStatus::Missed, AttendanceStatus::Makeup, AttendanceStatus::Attached]);

    it('does not carry over a prior row that only holds a rollcall signature', function () {
        $trooper = rollcallTrooper($this->section);
        rollcallMark($this->section, ROLLCALL_PRIOR_WEEK, $trooper, null, ['rollcall' => RollcallSignature::WillAttend]);

        expect(rollcallRow($this->actingAs($this->leader), $this->section, $trooper)['practice']['value'])->toBeNull();
    });

    it('prefers the mark saved for the week over what the prior week suggests', function () {
        $trooper = rollcallTrooper($this->section);
        rollcallMark($this->section, ROLLCALL_PRIOR_WEEK, $trooper, AttendanceStatus::Present);
        rollcallMark($this->section, ROLLCALL_WEEK, $trooper, AttendanceStatus::Missed);

        expect(rollcallRow($this->actingAs($this->leader), $this->section, $trooper)['practice'])->toMatchArray(['value' => 'missed', 'origin' => 'saved']);
    });

    it('carries from the week before the one asked for, not always the last one', function () {
        $trooper = rollcallTrooper($this->section);
        rollcallMark($this->section, '2026-09-12', $trooper, AttendanceStatus::Present);

        expect(rollcallRow($this->actingAs($this->leader), $this->section, $trooper, '?week=2026-09-19')['practice']['origin'])->toBe('carried')
            ->and(rollcallRow($this->actingAs($this->leader), $this->section, $trooper, '?week=2026-09-26')['practice']['origin'])->toBeNull();
    });

    it('does not carry a prior week from a different section', function () {
        $trooper = rollcallTrooper($this->section);
        rollcallMark($this->sibling, ROLLCALL_PRIOR_WEEK, $trooper, AttendanceStatus::Present);

        expect(rollcallRow($this->actingAs($this->leader), $this->section, $trooper)['practice']['value'])->toBeNull();
    });

    it('shows the rollcall signature, and unsigned when there is none', function () {
        $signed = rollcallTrooper($this->section);
        $silent = rollcallTrooper($this->section);
        rollcallMark($this->section, ROLLCALL_WEEK, $signed, null, ['rollcall' => RollcallSignature::CannotAttend]);

        expect(rollcallRow($this->actingAs($this->leader), $this->section, $signed)['rollcall'])->toBe('cannot_attend')
            ->and(rollcallRow($this, $this->section, $silent)['rollcall'])->toBe('unsigned');
    });
});

describe('leave (3.4)', function () {
    it('derives loa for a trooper on leave that week, over anything saved or carried, and carries the badge props', function () {
        $trooper = rollcallTrooper($this->section);
        rollcallMark($this->section, ROLLCALL_PRIOR_WEEK, $trooper, AttendanceStatus::Present);
        rollcallMark($this->section, ROLLCALL_WEEK, $trooper, AttendanceStatus::Missed);
        Loa::factory()->create(['trooper_id' => $trooper->id, 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-30']);

        $row = rollcallRow($this->actingAs($this->leader), $this->section, $trooper);

        expect($row['practice'])->toBe(['value' => 'loa', 'origin' => 'leave', 'source' => null])
            ->and($row['loa']['state'])->toBe('covered')
            ->and($row['loa']['endsAt'])->toBe('2026-10-30');
    });

    it('leaves a trooper whose leave needs review as an ordinary row, flagged for a person', function () {
        $trooper = rollcallTrooper($this->section);
        Loa::factory()->needsReview()->create(['trooper_id' => $trooper->id]);

        $row = rollcallRow($this->actingAs($this->leader), $this->section, $trooper);

        expect($row['practice']['origin'])->toBeNull()
            ->and($row['loa']['state'])->toBe('needs_review');
    });

    it('does not derive loa for a leave that ended before the week or begins after it', function () {
        $past = rollcallTrooper($this->section);
        $later = rollcallTrooper($this->section);
        Loa::factory()->create(['trooper_id' => $past->id, 'starts_at' => '2026-09-01', 'ends_at' => '2026-10-02']);
        Loa::factory()->create(['trooper_id' => $later->id, 'starts_at' => '2026-10-10', 'ends_at' => '2026-10-30']);

        expect(rollcallRow($this->actingAs($this->leader), $this->section, $past)['loa']['state'])->toBe('none')
            ->and(rollcallRow($this, $this->section, $later)['loa']['state'])->toBe('none');
    });

    it('works with no leave cached at all', function () {
        $trooper = rollcallTrooper($this->section);

        expect(Loa::query()->count())->toBe(0)
            ->and(rollcallRow($this->actingAs($this->leader), $this->section, $trooper)['loa']['state'])->toBe('none');
    });
});

describe('the pre-fill banner', function () {
    it('counts what was carried and what is saved, out of the troopers not on leave', function () {
        $carried = rollcallTrooper($this->section);
        $saved = rollcallTrooper($this->section);
        $onLeave = rollcallTrooper($this->section);
        $blank = rollcallTrooper($this->section);
        rollcallMark($this->section, ROLLCALL_PRIOR_WEEK, $carried, AttendanceStatus::Present);
        rollcallMark($this->section, ROLLCALL_WEEK, $saved, AttendanceStatus::Present);
        Loa::factory()->create(['trooper_id' => $onLeave->id, 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-30']);

        // The section's own leader is a fifth row, so eligible is 4 (everyone but the trooper on leave).
        rollcallOpen($this->actingAs($this->leader), $this->section)->assertInertia(fn ($page) => $page
            ->where('banner', ['eligible' => 4, 'saved' => 1, 'carried' => 1, 'fromWeek' => ROLLCALL_PRIOR_WEEK])
            ->where('troopers', fn ($rows) => collect($rows)->contains('id', $blank->id)));
    });

    it('says there is no earlier week when the section has no prior practice', function () {
        rollcallTrooper($this->section);

        rollcallOpen($this->actingAs($this->leader), $this->section)->assertInertia(fn ($page) => $page
            ->where('banner.carried', 0)
            ->where('banner.fromWeek', null));
    });

    it('names the prior week even when nobody was present to carry over', function () {
        $trooper = rollcallTrooper($this->section);
        rollcallMark($this->section, ROLLCALL_PRIOR_WEEK, $trooper, AttendanceStatus::Missed);

        rollcallOpen($this->actingAs($this->leader), $this->section)->assertInertia(fn ($page) => $page
            ->where('banner.carried', 0)
            ->where('banner.fromWeek', ROLLCALL_PRIOR_WEEK));
    });
});

describe('source and last sync (AD-20)', function () {
    it('says plainly when neither the roster nor leave has ever synced', function () {
        rollcallOpen($this->actingAs($this->leader), $this->section)->assertInertia(fn ($page) => $page
            ->where('sync.roster', ['source' => 'Cav roster', 'lastSyncedAt' => null, 'stale' => false])
            ->where('sync.loa.neverSynced', true)
            ->where('sync.loa.source', 'Forum thread'));
    });

    it('shows the last successful roster sync, and flags a later run that did not succeed', function () {
        SyncRun::factory()->create(['source' => 'roster', 'finished_at' => '2026-10-08 09:30:00']);

        rollcallOpen($this->actingAs($this->leader), $this->section)->assertInertia(fn ($page) => $page
            ->where('sync.roster.lastSyncedAt', fn ($at) => str_starts_with($at, '2026-10-08T09:30:00'))
            ->where('sync.roster.stale', false));

        SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Failure, 'finished_at' => '2026-10-08 11:30:00']);

        rollcallOpen($this, $this->section)->assertInertia(fn ($page) => $page
            ->where('sync.roster.stale', true)
            ->where('sync.roster.lastSyncedAt', fn ($at) => str_starts_with($at, '2026-10-08T09:30:00')));

    });

    it('does not mix up the roster sync with the leave sync', function () {
        SyncRun::factory()->create(['source' => 'loa', 'finished_at' => '2026-10-08 10:00:00']);

        rollcallOpen($this->actingAs($this->leader), $this->section)->assertInertia(fn ($page) => $page
            ->where('sync.roster.lastSyncedAt', null)
            ->where('sync.loa.neverSynced', false));
    });
});

describe('the template (3.6, 3.10)', function () {
    it('carries the company\'s active template, with its version id, for the browser to render from', function () {
        $version = app(TemplateVersionRepository::class)->author(
            Unit::query()->where('type', UnitType::Company->value)->value('id'),
            ArtifactType::RollcallBbcode,
            '[B]{{unit}}[/B]',
            User::factory()->create()->id,
        );

        rollcallOpen($this->actingAs($this->leader), $this->section)->assertInertia(fn ($page) => $page
            ->where('template.state', 'resolved')
            ->where('template.versionId', $version->id)
            ->where('template.body', '[B]{{unit}}[/B]')
            ->where('template.version', 1));
    });

    it('says plainly when the company has no active template, with no body', function () {
        rollcallOpen($this->actingAs($this->leader), $this->section)->assertInertia(fn ($page) => $page
            ->where('template.state', 'no_active_template')
            ->where('template.body', null)
            ->where('template.versionId', null));
    });

    it('carries each trooper\'s username and full rank for the template to print', function () {
        $trooper = rollcallTrooper($this->section, ['username' => 'Hollis.D', 'rank' => 'Specialist', 'rank_short' => 'SPC']);

        expect(rollcallRow($this->actingAs($this->leader), $this->section, $trooper))->toMatchArray(['username' => 'Hollis.D', 'rank' => 'Specialist', 'rankShort' => 'SPC']);
    });
});

describe('reading', function () {
    it('is read-only: opening a week writes no practice, no mark and no audit entry', function () {
        rollcallTrooper($this->section);
        $counts = fn () => [DB::table('section_practices')->count(), DB::table('attendance')->count(), DB::table('audit_log')->count()];
        $before = $counts();

        rollcallOpen($this->actingAs($this->leader), $this->section)->assertOk();
        rollcallOpen($this, $this->section, '?week=2026-09-12')->assertOk();

        expect($counts())->toBe($before);
    });

    it('reads in a constant number of queries however many troopers there are', function () {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            rollcallOpen($this->actingAs($this->leader), $this->section)->assertOk();

            return count(DB::getQueryLog());
        };

        // The baseline already exercises every read (the roster, both weeks' marks and the leave), so what grows is only
        // the number of rows.
        $trooper = rollcallTrooper($this->section);
        rollcallMark($this->section, ROLLCALL_PRIOR_WEEK, $trooper, AttendanceStatus::Present);
        rollcallMark($this->section, ROLLCALL_WEEK, $trooper, AttendanceStatus::Present);
        Loa::factory()->create(['trooper_id' => $trooper->id, 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-30']);
        $few = $count();

        foreach (range(1, 8) as $n) {
            $extra = rollcallTrooper($this->section);
            rollcallMark($this->section, ROLLCALL_PRIOR_WEEK, $extra, AttendanceStatus::Present);
            rollcallMark($this->section, ROLLCALL_WEEK, $extra, AttendanceStatus::Present);
            Loa::factory()->create(['trooper_id' => $extra->id, 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-30']);
        }

        expect($count())->toBe($few);
    });
});
