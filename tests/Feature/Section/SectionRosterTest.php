<?php

use App\Models\PlacementSource;
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

// Story 2.8: a section's roster, gated by the ScopeResolver and honest about how current it is.

/**
 * Regiment > two battalions; Alpha company (1st platoon: sections 1 and 2) under the first.
 *
 * @return array<string, Unit>
 */
function rosterTree(): array
{
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
    $platoon = $repo->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $sectionOne = $repo->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);
    $sectionTwo = $repo->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $platoon->id);
    $otherBattalion = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);
    $otherCompany = $repo->create(UnitType::Company, 'Bravo Company', 'B/2-7', $otherBattalion->id);
    $otherPlatoon = $repo->create(UnitType::Platoon, '1st Platoon', '1/B/2-7', $otherCompany->id);
    $otherSection = $repo->create(UnitType::Section, '1st Section', '1/1/B/2-7', $otherPlatoon->id);

    return compact('regiment', 'battalion', 'company', 'platoon', 'sectionOne', 'sectionTwo', 'otherBattalion', 'otherSection');
}

function leaderOf($unit): User
{
    $user = User::factory()->create();
    PortalRoleGrant::factory()->create(['user_id' => $user->id, 'unit_id' => $unit->id]);

    return $user;
}

function rosterSync(User $user, $section)
{
    return test()->actingAs($user)->get("/roster/{$section->id}");
}

/** A leader whose scope comes from their synced Cav billet, not from a grant (1.16, AD-7). */
function billetLeaderOf($unit, int $positionId): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $positionId]);
    Trooper::factory()->create(['cav_id' => 5000 + $positionId, 'cav_position_id' => $positionId, 'status' => TrooperStatus::Combat]);

    return User::factory()->create(['cav_id' => 5000 + $positionId]);
}

describe('access', function () {
    it('gives a section leader their own section only, from their Cav billet', function () {
        $tree = rosterTree();
        $leader = billetLeaderOf($tree['sectionOne'], 201);

        $this->actingAs($leader)->get("/roster/{$tree['sectionOne']->id}")->assertOk();
        $this->get("/roster/{$tree['sectionTwo']->id}")->assertForbidden();
        $this->get('/roster')->assertRedirect("/roster/{$tree['sectionOne']->id}");
    });

    it('gives a platoon leader every section of the platoon, from their Cav billet', function () {
        $tree = rosterTree();
        $leader = billetLeaderOf($tree['platoon'], 190);

        $this->actingAs($leader)->get("/roster/{$tree['sectionTwo']->id}")
            ->assertOk()->assertInertia(fn ($page) => $page->has('sections', 2));
        $this->get("/roster/{$tree['otherSection']->id}")->assertForbidden();
    });

    it('stops showing a section once the leader\'s billet no longer commands it', function () {
        $tree = rosterTree();
        $leader = billetLeaderOf($tree['sectionOne'], 201);
        $this->actingAs($leader)->get("/roster/{$tree['sectionOne']->id}")->assertOk();

        // The next roster sync moves them to a billet that commands nothing.
        Trooper::where('cav_id', $leader->cav_id)->update(['cav_position_id' => 999]);

        $this->get("/roster/{$tree['sectionOne']->id}")->assertForbidden();
    });

    it('requires authentication', function () {
        $tree = rosterTree();

        $this->get('/roster')->assertRedirect('/login');
        $this->get("/roster/{$tree['sectionOne']->id}")->assertRedirect('/login');
    });

    it('lets an Admin open any section', function () {
        $tree = rosterTree();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get("/roster/{$tree['otherSection']->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Section/Roster')->where('section.id', $tree['otherSection']->id));
    });

    it('lets a leader open a section inside their scope', function () {
        $tree = rosterTree();

        $this->actingAs(leaderOf($tree['platoon']))->get("/roster/{$tree['sectionTwo']->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('section.name', '2nd Section'));
    });

    it('refuses a section outside the leader\'s scope with 403, server-side (AD-8)', function () {
        $tree = rosterTree();
        Trooper::factory()->create(['username' => 'Secret.S', 'primary_section_id' => $tree['otherSection']->id]);

        $this->actingAs(leaderOf($tree['platoon']))->get("/roster/{$tree['otherSection']->id}")->assertForbidden();
    });

    it('refuses everything to a user with no scope', function () {
        $tree = rosterTree();

        $this->actingAs(User::factory()->create())->get("/roster/{$tree['sectionOne']->id}")->assertForbidden();
    });

    it('404s a unit that is not a section, and a unit that does not exist', function () {
        $tree = rosterTree();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get("/roster/{$tree['platoon']->id}")->assertNotFound();
        $this->get('/roster/999999')->assertNotFound();
        $this->get('/roster/abc')->assertNotFound();
        $this->get('/roster/99999999999999999999')->assertNotFound();
    });

    it('refuses an out-of-scope unit with 403 whatever its type, so the answer never says it is not a section', function () {
        $tree = rosterTree();
        $leader = leaderOf($tree['sectionOne']);

        // A section and a non-section outside scope get the same answer; only an in-scope platoon gets the 404.
        $this->actingAs($leader)->get("/roster/{$tree['otherSection']->id}")->assertForbidden();
        $this->get("/roster/{$tree['company']->id}")->assertForbidden();
        $this->get("/roster/{$tree['otherBattalion']->id}")->assertForbidden();

        $this->actingAs(leaderOf($tree['platoon']))->get("/roster/{$tree['platoon']->id}")->assertNotFound();
    });

    it('narrows to the previewed unit while an Admin is viewing as one (AD-9)', function () {
        $tree = rosterTree();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $tree['platoon']->id]);

        $this->get("/roster/{$tree['sectionOne']->id}")->assertOk();
        $this->get("/roster/{$tree['otherSection']->id}")->assertForbidden();
    });
});

describe('opening the roster', function () {
    it('sends /roster to the first section in scope, in chain-of-command order', function () {
        $tree = rosterTree();

        $this->actingAs(leaderOf($tree['platoon']))->get('/roster')->assertRedirect("/roster/{$tree['sectionOne']->id}");
        $this->actingAs(User::factory()->admin()->create())->get('/roster')->assertRedirect("/roster/{$tree['sectionOne']->id}");
    });

    it('renders an empty state, not an error, for a user with no section in scope', function () {
        rosterTree();

        $this->actingAs(User::factory()->create())->get('/roster')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Section/Roster')->where('section', null)->where('sections', [])->where('sync', null));
    });

    it('lists only the sections the viewer may open, for the switcher', function () {
        $tree = rosterTree();

        $this->actingAs(leaderOf($tree['platoon']))->get("/roster/{$tree['sectionOne']->id}")
            ->assertInertia(fn ($page) => $page
                ->has('sections', 2)
                ->where('sections.0.id', $tree['sectionOne']->id)
                ->where('sections.0.callsign', '1/1/A/1-7')
                ->where('sections.1.id', $tree['sectionTwo']->id));
    });
});

describe('the roster', function () {
    it('lists the troopers placed in that section, with what the page shows', function () {
        $tree = rosterTree();
        Trooper::factory()->create([
            'cav_id' => 3099, 'username' => 'Hollis.D', 'rank' => 'Private', 'mos' => '11B',
            'status' => TrooperStatus::Combat, 'primary_section_id' => $tree['sectionOne']->id,
        ]);
        Trooper::factory()->create(['username' => 'Elsewhere.E', 'primary_section_id' => $tree['sectionTwo']->id]);
        Trooper::factory()->create(['username' => 'Unplaced.U', 'primary_section_id' => null]);

        $this->actingAs(User::factory()->admin()->create())->get("/roster/{$tree['sectionOne']->id}")
            ->assertInertia(fn ($page) => $page
                ->has('troopers', 1)
                ->where('troopers.0.cav_id', 3099)
                ->where('troopers.0.username', 'Hollis.D')
                ->where('troopers.0.rank', 'Private')
                ->where('troopers.0.mos', '11B')
                ->where('troopers.0.status', 'combat')
                ->where('troopers.0.placed_by_hand', false));
    });

    it('keeps a departed trooper on the roster, marked and last', function () {
        $tree = rosterTree();
        $section = $tree['sectionOne']->id;
        Trooper::factory()->create(['username' => 'Alpha.A', 'status' => TrooperStatus::Inactive, 'primary_section_id' => $section]);
        Trooper::factory()->create(['username' => 'Zulu.Z', 'status' => TrooperStatus::Reserve, 'primary_section_id' => $section]);
        Trooper::factory()->create(['username' => 'Mike.M', 'status' => TrooperStatus::Eloa, 'primary_section_id' => $section]);

        $this->actingAs(User::factory()->admin()->create())->get("/roster/{$section}")
            ->assertInertia(fn ($page) => $page
                ->has('troopers', 3)
                ->where('troopers.0.username', 'Mike.M')
                ->where('troopers.1.username', 'Zulu.Z')
                ->where('troopers.2.username', 'Alpha.A')
                ->where('troopers.2.status', 'inactive'));
    });

    it('flags a trooper an Admin placed by hand', function () {
        $tree = rosterTree();
        Trooper::factory()->create(['username' => 'Placed.P', 'primary_section_id' => $tree['sectionOne']->id, 'placement_source' => PlacementSource::Manual]);
        Trooper::factory()->create(['username' => 'Synced.S', 'primary_section_id' => $tree['sectionOne']->id, 'placement_source' => PlacementSource::Sync]);

        $this->actingAs(User::factory()->admin()->create())->get("/roster/{$tree['sectionOne']->id}")
            ->assertInertia(fn ($page) => $page
                ->where('troopers.0.username', 'Placed.P')->where('troopers.0.placed_by_hand', true)
                ->where('troopers.1.username', 'Synced.S')->where('troopers.1.placed_by_hand', false));
    });

    it('sends an empty list for a section nobody is placed in', function () {
        $tree = rosterTree();

        $this->actingAs(User::factory()->admin()->create())->get("/roster/{$tree['sectionOne']->id}")
            ->assertOk()->assertInertia(fn ($page) => $page->where('troopers', []));
    });
});

describe('where the data comes from and how current it is (AD-20)', function () {
    it('says the roster has never been synced when there is no run', function () {
        $tree = rosterTree();

        rosterSync(User::factory()->admin()->create(), $tree['sectionOne'])
            ->assertInertia(fn ($page) => $page
                ->where('sync.source', 'Cav roster')->where('sync.lastSyncedAt', null)
                ->where('sync.stale', false)->where('sync.latestStatus', null));
    });

    it('shows the last successful sync time and is not stale', function () {
        $tree = rosterTree();
        $finished = now()->subHour()->startOfSecond();
        SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Success, 'finished_at' => $finished]);

        rosterSync(User::factory()->admin()->create(), $tree['sectionOne'])
            ->assertInertia(fn ($page) => $page
                ->where('sync.lastSyncedAt', $finished->toIso8601String())
                ->where('sync.stale', false)->where('sync.latestStatus', 'success'));
    });

    it('is stale after a failed run, and still reports the last success, not the failed attempt', function () {
        $tree = rosterTree();
        $good = now()->subHours(3)->startOfSecond();
        $failed = now()->subHour()->startOfSecond();
        SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Success, 'finished_at' => $good]);
        SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Failure, 'finished_at' => $failed, 'error' => 'Cav unreachable']);
        Trooper::factory()->create(['primary_section_id' => $tree['sectionOne']->id]);

        rosterSync(User::factory()->admin()->create(), $tree['sectionOne'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sync.lastSyncedAt', $good->toIso8601String())
                ->where('sync.stale', true)->where('sync.latestStatus', 'failure')
                ->where('sync.latestFinishedAt', $failed->toIso8601String())
                ->has('troopers', 1));
    });

    it('is stale after a partial run', function () {
        $tree = rosterTree();
        SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Success, 'finished_at' => now()->subHours(2)]);
        SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Partial, 'finished_at' => now()->subHour(), 'errored' => 3]);

        rosterSync(User::factory()->admin()->create(), $tree['sectionOne'])
            ->assertInertia(fn ($page) => $page->where('sync.stale', true)->where('sync.latestStatus', 'partial'));
    });

    it('is current again once a run succeeds after a failure', function () {
        $tree = rosterTree();
        SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Failure, 'finished_at' => now()->subHours(2)]);
        SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Success, 'finished_at' => now()->subHour()]);

        rosterSync(User::factory()->admin()->create(), $tree['sectionOne'])
            ->assertInertia(fn ($page) => $page->where('sync.stale', false));
    });

    it('ignores a run that is still in progress', function () {
        $tree = rosterTree();
        SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::Success, 'finished_at' => now()->subHour()]);
        SyncRun::factory()->create(['source' => 'roster', 'status' => SyncRunStatus::InProgress, 'finished_at' => null]);

        rosterSync(User::factory()->admin()->create(), $tree['sectionOne'])
            ->assertInertia(fn ($page) => $page->where('sync.stale', false)->where('sync.latestStatus', 'success'));
    });

    it('does not count another source\'s runs', function () {
        $tree = rosterTree();
        SyncRun::factory()->create(['source' => 'loa', 'status' => SyncRunStatus::Failure, 'finished_at' => now()]);

        rosterSync(User::factory()->admin()->create(), $tree['sectionOne'])
            ->assertInertia(fn ($page) => $page->where('sync.stale', false)->where('sync.latestStatus', null));
    });
});
