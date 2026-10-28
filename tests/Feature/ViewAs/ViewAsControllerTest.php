<?php

use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\SyncRun;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

// The switcher end to end (Story 1.13): what the picker is offered, what entering and leaving do
// to the session and the shared `viewAs` prop, and that the preview is read-only server-side.

/**
 * 7CAV → 1-7 → A/1-7 → {1/A/1-7 → 1/1/A/1-7}, plus a sibling battalion 2-7.
 *
 * @return array<string, Unit>
 */
function vcTree(): array
{
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
    $platoon = $repo->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $section = $repo->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);
    $section2 = $repo->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $platoon->id);
    $otherBattalion = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);

    return compact('regiment', 'battalion', 'company', 'platoon', 'section', 'section2', 'otherBattalion');
}

function vcLeaderOf(Unit $unit, int $cavId, string $username, ?string $rank = null): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $cavId + 10000]);
    Trooper::factory()->create([
        'cav_id' => $cavId, 'cav_position_id' => $cavId + 10000, 'status' => TrooperStatus::Combat, 'rank' => $rank,
    ]);

    return User::factory()->create(['cav_id' => $cavId, 'username' => $username]);
}

describe('options', function () {
    it('offers a leader exactly the units in their real scope, and no people', function () {
        $tree = vcTree();
        $platoonLeader = vcLeaderOf($tree['platoon'], 100, 'Brennan.J', '1LT');

        $this->actingAs($platoonLeader)->getJson('/view-as/options')
            ->assertOk()
            ->assertJsonPath('people', null)
            ->assertJsonPath('current', null)
            ->assertExactJson([
                'units' => [
                    ['id' => $tree['platoon']->id, 'designation' => '1/A/1-7', 'name' => '1st Platoon', 'type' => 'platoon'],
                    ['id' => $tree['section']->id, 'designation' => '1/1/A/1-7', 'name' => '1st Section', 'type' => 'section'],
                    ['id' => $tree['section2']->id, 'designation' => '2/1/A/1-7', 'name' => '2nd Section', 'type' => 'section'],
                ],
                'people' => null,
                'current' => null,
            ]);
    });

    it('offers a leaf leader only their own section (AC #6)', function () {
        $tree = vcTree();
        $sectionLeader = vcLeaderOf($tree['section'], 100, 'Vasquez.R', 'SGT');

        $this->actingAs($sectionLeader)->getJson('/view-as/options')
            ->assertJsonCount(1, 'units')
            ->assertJsonPath('units.0.id', $tree['section']->id);
    });

    it('offers a user with no scope nothing to view as', function () {
        vcTree();

        $this->actingAs(User::factory()->serving()->create())->getJson('/view-as/options')
            ->assertJsonCount(0, 'units')
            ->assertJsonPath('people', null);
    });

    it('offers an Admin every unit and every other person, with rank and commanded unit', function () {
        $tree = vcTree();
        $admin = User::factory()->admin()->create(['username' => 'Gryspeert.J']);
        $leader = vcLeaderOf($tree['section'], 100, 'Vasquez.R', 'SGT');
        $plain = User::factory()->create(['username' => 'Doyle.K']);

        $this->actingAs($admin)->getJson('/view-as/options')
            ->assertJsonCount(7, 'units')
            ->assertJsonPath('people', [
                ['kind' => 'user', 'id' => $plain->id, 'label' => 'Doyle.K', 'designation' => null, 'admin' => false, 'account' => true],
                ['kind' => 'user', 'id' => $leader->id, 'label' => 'SGT Vasquez.R', 'designation' => '1/1/A/1-7', 'admin' => false, 'account' => true],
            ]);
    });

    it('marks the active target as current', function () {
        $tree = vcTree();
        $commander = vcLeaderOf($tree['company'], 100, 'Hale.S', 'CPT');

        $this->actingAs($commander)->post('/view-as', ['kind' => 'unit', 'id' => $tree['platoon']->id]);

        $this->getJson('/view-as/options')->assertJsonPath('current', ['kind' => 'unit', 'id' => $tree['platoon']->id]);
    });

    it('requires authentication', function () {
        $this->get('/view-as/options')->assertRedirect('/login');
    });
});

describe('entering and leaving', function () {
    it('enters a unit in scope, and every page then carries the banner prop (AC #1, #2)', function () {
        $tree = vcTree();
        $commander = vcLeaderOf($tree['company'], 100, 'Hale.S', 'CPT');

        $this->actingAs($commander)
            ->from('/settings/org-structure')
            ->post('/view-as', ['kind' => 'unit', 'id' => $tree['platoon']->id])
            ->assertRedirect('/settings/org-structure')
            ->assertSessionHasNoErrors();

        $this->get('/home')->assertInertia(fn ($page) => $page->where('viewAs', [
            'kind' => 'unit',
            'id' => $tree['platoon']->id,
            'label' => '1/A/1-7',
            'designation' => '1/A/1-7',
            'unitName' => '1st Platoon',
            'admin' => false,
        ]));
    });

    it('shares no viewAs prop when view-as is off', function () {
        $this->actingAs(User::factory()->serving()->create())->get('/home')
            ->assertInertia(fn ($page) => $page->where('viewAs', null));
    });

    it('refuses a tampered target above or outside real authority, storing nothing (AC #3)', function (string $target) {
        $tree = vcTree();
        $platoonLeader = vcLeaderOf($tree['platoon'], 100, 'Brennan.J', '1LT');

        $this->actingAs($platoonLeader)
            ->post('/view-as', ['kind' => 'unit', 'id' => $tree[$target]->id])
            ->assertSessionHasErrors(['view_as' => 'You can only view as a unit inside your own scope.']);

        $this->get('/home')->assertInertia(fn ($page) => $page->where('viewAs', null));
    })->with(['company', 'regiment', 'otherBattalion']);

    it('refuses a non-Admin viewing as a person', function () {
        $tree = vcTree();
        $commander = vcLeaderOf($tree['company'], 100, 'Hale.S', 'CPT');
        $sectionLeader = vcLeaderOf($tree['section'], 200, 'Vasquez.R', 'SGT');

        $this->actingAs($commander)
            ->post('/view-as', ['kind' => 'user', 'id' => $sectionLeader->id])
            ->assertSessionHasErrors(['view_as' => 'Only an Admin can view the portal as another person.']);
    });

    it('validates the shape of the request', function (array $payload) {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/view-as', $payload)
            ->assertSessionHasErrors();
    })->with([
        'missing kind' => [['id' => 1]],
        'unknown kind' => [['kind' => 'regiment', 'id' => 1]],
        'non-numeric id' => [['kind' => 'unit', 'id' => 'abc']],
    ]);

    it('lets an Admin view as a person, sharing their label and unit', function () {
        $tree = vcTree();
        $admin = User::factory()->admin()->create();
        $leader = vcLeaderOf($tree['section'], 100, 'Vasquez.R', 'SGT');

        $this->actingAs($admin)->post('/view-as', ['kind' => 'user', 'id' => $leader->id])->assertSessionHasNoErrors();

        $this->get('/home')->assertInertia(fn ($page) => $page
            ->where('viewAs.label', 'SGT Vasquez.R')
            ->where('viewAs.designation', '1/1/A/1-7')
            ->where('viewAs.admin', false));
    });

    it('exits back to the real scope (AC #5)', function () {
        $tree = vcTree();
        $commander = vcLeaderOf($tree['company'], 100, 'Hale.S', 'CPT');
        $this->actingAs($commander)->post('/view-as', ['kind' => 'unit', 'id' => $tree['platoon']->id]);

        $this->from('/home')->delete('/view-as')->assertRedirect('/home');

        $this->get('/home')->assertInertia(fn ($page) => $page->where('viewAs', null));
    });

    it('drops a stored target that no longer holds, on the next request (AC #4)', function () {
        $tree = vcTree();
        $staff = User::factory()->serving()->create();
        $grant = PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $tree['company']->id]);
        $this->actingAs($staff)->post('/view-as', ['kind' => 'unit', 'id' => $tree['platoon']->id])->assertSessionHasNoErrors();

        $grant->delete();

        $this->get('/home')
            ->assertInertia(fn ($page) => $page->where('viewAs', null))
            ->assertSessionMissing('view_as');
    });
});

describe('read-only preview', function () {
    it('refuses every write while viewing as, server-side (AD-6)', function () {
        $tree = vcTree();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $tree['platoon']->id]);

        $this->post('/settings/org-structure', ['type' => 'section', 'name' => 'Ghost Section', 'parent_id' => $tree['platoon']->id, 'reason' => 'x'])
            ->assertForbidden();

        expect(Unit::where('name', 'Ghost Section')->exists())->toBeFalse();
    });

    it('still lets the viewer leave the preview, switch target, and sign out', function () {
        $tree = vcTree();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $tree['platoon']->id]);

        $this->post('/view-as', ['kind' => 'unit', 'id' => $tree['section']->id])->assertSessionHasNoErrors();
        $this->delete('/view-as')->assertRedirect();
        $this->post('/view-as', ['kind' => 'unit', 'id' => $tree['section']->id]);
        $this->post('/logout')->assertRedirect();

        $this->assertGuest();
    });

    it('lets writes through again once view-as is exited', function () {
        $tree = vcTree();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $tree['platoon']->id]);
        $this->delete('/view-as');

        $this->post('/settings/org-structure', ['type' => 'section', 'name' => 'Real Section', 'parent_id' => $tree['platoon']->id, 'reason' => 'stand-up'])
            ->assertRedirect();

        expect(Unit::where('name', 'Real Section')->exists())->toBeTrue();
    });

    // Every write route, by method — an Admin, so a 403 can only come from the preview guard,
    // never from the controller's own Admin gate — and the database untouched each time.
    it('refuses each kind of write while viewing as, leaving the data unchanged', function (Closure $request, Closure $unchanged) {
        $tree = vcTree();
        $admin = User::factory()->admin()->create();
        $grant = PortalRoleGrant::factory()->create(['user_id' => User::factory()->create()->id, 'unit_id' => $tree['section']->id]);
        $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $tree['platoon']->id]);

        $request($this, $tree, $grant)->assertForbidden();

        expect($unchanged($tree, $grant))->toBeTrue();
    })->with([
        'PATCH a unit' => [
            fn ($test, $tree) => $test->patch("/settings/org-structure/{$tree['section']->id}", ['name' => 'Renamed', 'callsign' => 'X', 'reason' => 'x']),
            fn ($tree) => $tree['section']->fresh()->name === '1st Section',
        ],
        'DELETE a unit' => [
            fn ($test, $tree) => $test->delete("/settings/org-structure/{$tree['section2']->id}", ['reason' => 'x']),
            fn ($tree) => $tree['section2']->fresh() !== null,
        ],
        'DELETE a staff grant' => [
            fn ($test, $tree, $grant) => $test->delete("/settings/staff-grants/{$grant->id}", ['reason' => 'x']),
            fn ($tree, $grant) => $grant->fresh() !== null,
        ],
        'POST a roster sync' => [
            fn ($test) => $test->post('/settings/roster-sync'),
            fn () => SyncRun::count() === 0,
        ],
    ]);

    it('lets a viewer switch sideways to another unit in real scope without exiting first', function () {
        $tree = vcTree();
        $commander = vcLeaderOf($tree['company'], 100, 'Hale.S', 'CPT');
        $this->actingAs($commander)->post('/view-as', ['kind' => 'unit', 'id' => $tree['section']->id]);

        $this->post('/view-as', ['kind' => 'unit', 'id' => $tree['section2']->id])->assertSessionHasNoErrors();

        // The picker still offers the whole real scope, not just the previewed subtree.
        $this->getJson('/view-as/options')
            ->assertJsonCount(4, 'units')
            ->assertJsonPath('current', ['kind' => 'unit', 'id' => $tree['section2']->id]);
    });
});

describe('Admin screens during a preview', function () {
    it('shows an Admin previewing a leader the leader\'s settings view, not the Admin\'s', function () {
        $tree = vcTree();
        $admin = User::factory()->admin()->create();
        $leader = vcLeaderOf($tree['section'], 100, 'Vasquez.R', 'SGT');
        $this->actingAs($admin)->post('/view-as', ['kind' => 'user', 'id' => $leader->id]);

        $this->get('/settings/audit-log')->assertForbidden();
        $this->get('/settings/org-structure')->assertInertia(fn ($page) => $page->where('isAdmin', false));
        // The leader would not get the username list, so neither does the Admin previewing them.
        $this->get('/settings/staff-grants')->assertInertia(fn ($page) => $page->where('isAdmin', false)->where('users', []));

        $this->delete('/view-as');

        $this->get('/settings/audit-log')->assertOk();
        $this->get('/settings/org-structure')->assertInertia(fn ($page) => $page->where('isAdmin', true));
        $this->get('/settings/staff-grants')->assertInertia(fn ($page) => $page->where('isAdmin', true)->has('users', 1));
    });

    it('keeps the Admin view when the previewed person is an Admin too', function () {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/view-as', ['kind' => 'user', 'id' => $otherAdmin->id]);

        $this->get('/settings/audit-log')->assertOk();
    });

    it('never makes a non-Admin act as one', function () {
        $tree = vcTree();
        $commander = vcLeaderOf($tree['company'], 100, 'Hale.S', 'CPT');
        $this->actingAs($commander)->post('/view-as', ['kind' => 'unit', 'id' => $tree['platoon']->id]);

        $this->get('/settings/audit-log')->assertForbidden();
    });
});

describe('audit log', function () {
    $entries = fn () => AuditLogEntry::query()->orderBy('id')->get()
        ->map(fn (AuditLogEntry $entry) => [$entry->actor_id, $entry->action, $entry->target_type, $entry->target_id])
        ->all();

    it('records starting and stopping a unit preview against the unit', function () use ($entries) {
        $tree = vcTree();
        $commander = vcLeaderOf($tree['company'], 100, 'Hale.S', 'CPT');

        $this->actingAs($commander)->post('/view-as', ['kind' => 'unit', 'id' => $tree['platoon']->id])->assertSessionHasNoErrors();
        $this->delete('/view-as');

        $platoon = (string) $tree['platoon']->id;
        expect($entries())->toBe([
            [$commander->id, 'view_as.started', 'Unit', $platoon],
            [$commander->id, 'view_as.stopped', 'Unit', $platoon],
        ])->and(AuditLogEntry::query()->whereNotNull('reason')->exists())->toBeFalse()
            ->and(AuditLogEntry::query()->pluck('content_digest')->unique()->all())
            ->toBe([hash('sha256', json_encode(collect(Unit::query()->find($tree['platoon']->id)->getAttributes())->sortKeys()->all(), JSON_THROW_ON_ERROR))]);
    });

    it('records an Admin previewing a person against that user', function () use ($entries) {
        $tree = vcTree();
        $admin = User::factory()->admin()->create();
        $leader = vcLeaderOf($tree['section'], 100, 'Vasquez.R', 'SGT');

        $this->actingAs($admin)->post('/view-as', ['kind' => 'user', 'id' => $leader->id])->assertSessionHasNoErrors();
        $this->delete('/view-as');

        expect($entries())->toBe([
            [$admin->id, 'view_as.started', 'User', (string) $leader->id],
            [$admin->id, 'view_as.stopped', 'User', (string) $leader->id],
        ]);
    });

    it('records the old target as stopped when switching sideways, and nothing for the same target twice', function () use ($entries) {
        $tree = vcTree();
        $commander = vcLeaderOf($tree['company'], 100, 'Hale.S', 'CPT');
        $this->actingAs($commander)->post('/view-as', ['kind' => 'unit', 'id' => $tree['section']->id]);
        $this->post('/view-as', ['kind' => 'unit', 'id' => $tree['section']->id]);

        $this->post('/view-as', ['kind' => 'unit', 'id' => $tree['section2']->id])->assertSessionHasNoErrors();

        expect($entries())->toBe([
            [$commander->id, 'view_as.started', 'Unit', (string) $tree['section']->id],
            [$commander->id, 'view_as.stopped', 'Unit', (string) $tree['section']->id],
            [$commander->id, 'view_as.started', 'Unit', (string) $tree['section2']->id],
        ]);
    });

    it('records a preview still on at sign-out as stopped', function () use ($entries) {
        $tree = vcTree();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $tree['platoon']->id]);

        $this->post('/logout')->assertRedirect();

        $this->assertGuest();
        expect($entries())->toBe([
            [$admin->id, 'view_as.started', 'Unit', (string) $tree['platoon']->id],
            [$admin->id, 'view_as.stopped', 'Unit', (string) $tree['platoon']->id],
        ]);
    });

    it('records nothing for a refused target or for leaving when no preview is on', function () {
        $tree = vcTree();
        $platoonLeader = vcLeaderOf($tree['platoon'], 100, 'Brennan.J', '1LT');

        $this->actingAs($platoonLeader)->post('/view-as', ['kind' => 'unit', 'id' => $tree['company']->id])->assertSessionHasErrors('view_as');
        $this->delete('/view-as');

        expect(AuditLogEntry::query()->count())->toBe(0);
    });

    it('shows the entries on the audit log, linked, and under the unit filter', function () {
        $tree = vcTree();
        $admin = User::factory()->admin()->create();
        $leader = vcLeaderOf($tree['section'], 100, 'Vasquez.R', 'SGT');
        $trooper = Trooper::query()->where('cav_id', 100)->sole();
        $trooper->forceFill(['primary_section_id' => $tree['section']->id, 'username' => 'Vasquez.R', 'rank_short' => 'SGT'])->save();

        $this->actingAs($admin)->post('/view-as', ['kind' => 'user', 'id' => $leader->id]);
        $this->delete('/view-as');
        $this->post('/view-as', ['kind' => 'unit', 'id' => $tree['platoon']->id]);
        $this->delete('/view-as');

        $links = function (string $query) {
            $data = null;
            $this->get('/settings/audit-log'.$query)->assertOk()->assertInertia(function ($page) use (&$data) {
                $data = $page->toArray()['props']['entries']['data'];
            });

            return $data;
        };

        $all = collect($links(''));
        expect($all->pluck('action')->all())->toBe(['view_as.stopped', 'view_as.started', 'view_as.stopped', 'view_as.started'])
            ->and($all->pluck('link')->unique()->values()->all())->toBe([
                ['href' => "/roster/{$tree['platoon']->id}", 'label' => '1/A/1-7'],
                ['href' => "/troopers/{$trooper->id}", 'label' => $trooper->fresh()->display_name],
            ]);

        // The section's filter finds the person preview (their trooper sits there) but not the platoon one; the
        // platoon's finds both; the other battalion's finds neither.
        expect(collect($links("?unit_id={$tree['section']->id}"))->pluck('target_type')->unique()->all())->toBe(['User'])
            ->and($links("?unit_id={$tree['platoon']->id}"))->toHaveCount(4)
            ->and($links("?unit_id={$tree['otherBattalion']->id}"))->toBe([])
            ->and(collect($links('?action=view_as.started'))->pluck('action')->unique()->all())->toBe(['view_as.started']);
    });
});
