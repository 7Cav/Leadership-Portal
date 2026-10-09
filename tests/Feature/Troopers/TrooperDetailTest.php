<?php

use App\Models\PortalRoleGrant;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Story 6.3: one trooper's milpac, the ID block, gated by the ScopeResolver.
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

        milpacOpen($this->actingAs($this->leader), $outside)->assertForbidden();
    });

    it('opens a trooper in the union of every subtree the viewer holds', function () {
        $staff = User::factory()->create();
        PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $this->section1->id]);
        PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $this->section2->id]);

        milpacOpen($this->actingAs($staff), milpacTrooper($this->section1))->assertOk();
        milpacOpen($this, milpacTrooper($this->section2))->assertOk();
    });

    it('refuses someone with no scope at all', function () {
        milpacOpen($this->actingAs(User::factory()->create()), milpacTrooper($this->section1))->assertForbidden();
    });

    it('lets an Admin open any placed trooper', function () {
        $admin = User::factory()->admin()->create();

        milpacOpen($this->actingAs($admin), milpacTrooper($this->section2))->assertOk();
    });

    it('never widens a view-as preview past the previewed leader\'s scope (1.13, AD-9)', function () {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->section1->id])->assertSessionHasNoErrors();

        milpacOpen($this, milpacTrooper($this->section1))->assertOk();
        milpacOpen($this, milpacTrooper($this->section2))->assertForbidden();
    });

    it('shows a trooper the sync has not placed to an Admin only', function () {
        $unplaced = Trooper::factory()->create();

        milpacOpen($this->actingAs($this->leader), $unplaced)->assertForbidden();
        milpacOpen($this->actingAs(User::factory()->admin()->create()), $unplaced)->assertOk();
    });

    it('answers a trooper that does not exist with 404', function () {
        $this->actingAs($this->leader)->get('/troopers/999999')->assertNotFound();
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
