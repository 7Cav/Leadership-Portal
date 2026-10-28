<?php

use App\Authorization\ScopeResolver;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

it('renders the grants list for any authenticated user', function () {
    $unit = Unit::factory()->create();
    $target = User::factory()->create();
    PortalRoleGrant::factory()->create(['user_id' => $target->id, 'unit_id' => $unit->id]);
    $user = User::factory()->serving()->create();

    $this->actingAs($user)->get('/settings/staff-grants')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/StaffGrants')
            ->has('grants', 1)
            ->where('isAdmin', false));
});

it('does not send the username list to a non-admin who opens the page by URL', function () {
    User::factory()->count(3)->create();
    $user = User::factory()->serving()->create();

    $this->actingAs($user)->get('/settings/staff-grants')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('isAdmin', false)
            ->where('users', []));
});

it('marks each grant by whether its holder is still serving, keeping a lapsed one listed', function () {
    $unit = Unit::factory()->create();
    $serving = User::factory()->serving()->create(['username' => 'Vasquez.R']);
    $departed = User::factory()->create(['username' => 'Doyle.K', 'cav_id' => Trooper::factory()->create(['status' => TrooperStatus::Inactive])->cav_id]);
    $unlinked = User::factory()->create(['username' => 'Hale.S']);
    foreach ([$serving, $departed, $unlinked] as $holder) {
        PortalRoleGrant::factory()->create(['user_id' => $holder->id, 'unit_id' => $unit->id]);
    }

    $this->actingAs(User::factory()->admin()->create())->get('/settings/staff-grants')
        ->assertInertia(fn ($page) => $page
            // Still listed, so an Admin can see the lapse and revoke the row on the record.
            ->has('grants', 3)
            ->where('grants', fn ($grants) => collect($grants)->pluck('holder_serving', 'username')->all() === [
                'Vasquez.R' => true,
                'Doyle.K' => false,
                'Hale.S' => false,
            ]));
});

it('lists exactly the serving troopers placed on the regiment as Regimental HQ staff, as the scope reads them', function () {
    $units = app(UnitRepository::class);
    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $eloa = $units->create(UnitType::Group, 'Extended Leave of Absence', 'ELOA', $regiment->id, UnitKind::Eloa);
    $s6 = $units->create(UnitType::Group, 'S6', 'S6', $regiment->id, UnitKind::Staff);
    $placed = function (?Unit $at, array $attributes): Trooper {
        $trooper = Trooper::factory()->create($attributes);
        $trooper->forceFill(['primary_section_id' => $at?->id])->save();

        return $trooper;
    };
    $troopers = [
        'chief' => $placed($regiment, ['username' => 'Tharen.R', 'rank_short' => 'COL', 'billet' => 'Regimental Chief of Staff']),
        'finance' => $placed($regiment, ['username' => 'Abbot.F', 'rank_short' => 'MAJ', 'billet' => 'Regimental Finance Officer', 'status' => TrooperStatus::Reserve]),
        'departed' => $placed($regiment, ['username' => 'Doyle.K', 'billet' => 'Regimental Adjutant General', 'status' => TrooperStatus::Inactive]),
        'eloa' => $placed($eloa, ['username' => 'Leave.E', 'status' => TrooperStatus::Eloa]),
        's6' => $placed($s6, ['username' => 'Wire.S']),
        'battalion' => $placed($battalion, ['username' => 'Bat.B']),
        'unplaced' => $placed(null, ['username' => 'Nowhere.N']),
    ];

    $this->actingAs(User::factory()->admin()->create())->get('/settings/staff-grants')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('regimentalHq', [
                ['id' => $troopers['finance']->id, 'name' => 'MAJ Abbot.F', 'billet' => 'Regimental Finance Officer'],
                ['id' => $troopers['chief']->id, 'name' => 'COL Tharen.R', 'billet' => 'Regimental Chief of Staff'],
            ]));

    // The list and the scope cannot disagree: whoever is listed reads the regiment, and no one else does.
    $scope = app(ScopeResolver::class);
    foreach ($troopers as $key => $trooper) {
        $user = User::factory()->create(['cav_id' => $trooper->cav_id]);
        expect($scope->realScope($user)->contains($regiment->id))->toBe(in_array($key, ['chief', 'finance'], true), $key);
    }
});

it('does not send the Regimental HQ staff list to a non-Admin, who still reads the grants as today', function () {
    $regiment = app(UnitRepository::class)->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    Trooper::factory()->create()->forceFill(['primary_section_id' => $regiment->id])->save();

    $this->actingAs(User::factory()->serving()->create())->get('/settings/staff-grants')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('isAdmin', false)
            ->where('regimentalHq', []));
});

it('excludes Admins from the target-user picker (AD-7 — a grant to an Admin is meaningless)', function () {
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->create();

    $this->actingAs($admin)->get('/settings/staff-grants')
        ->assertInertia(fn ($page) => $page
            ->where('users', fn ($users) => collect($users)->pluck('id')->all() === [$staff->id]));
});

it('labels each unit with its chain below the root, so two same-named platoons read apart', function () {
    $units = app(UnitRepository::class);
    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $alpha = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $regiment->id);
    $bravo = $units->create(UnitType::Company, 'Bravo Company', 'B/1-7', $regiment->id);
    $alphaFirst = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $alpha->id);
    $bravoFirst = $units->create(UnitType::Platoon, '1st Platoon', '1/B/1-7', $bravo->id);
    PortalRoleGrant::factory()->create(['user_id' => User::factory()->create()->id, 'unit_id' => $bravoFirst->id]);

    $this->actingAs(User::factory()->admin()->create())->get('/settings/staff-grants')
        ->assertInertia(fn ($page) => $page
            ->where('units', fn ($list) => collect($list)->pluck('label', 'id')->all() === [
                $regiment->id => '7th Cavalry Regiment',
                $alpha->id => 'Alpha Company',
                $alphaFirst->id => 'Alpha Company › 1st Platoon',
                $bravo->id => 'Bravo Company',
                $bravoFirst->id => 'Bravo Company › 1st Platoon',
            ])
            ->where('grants.0.unit_label', 'Bravo Company › 1st Platoon'));
});

it('lets an Admin grant a non-admin staff user scope at a chosen node (AC #1)', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();

    $this->actingAs($admin)->post('/settings/staff-grants', [
        'user_id' => $target->id,
        'unit_id' => $unit->id,
        'reason' => 'S1 staff, no line billet',
    ])->assertRedirect();

    $grant = PortalRoleGrant::where('user_id', $target->id)->where('unit_id', $unit->id)->first();
    expect($grant)->not->toBeNull();
});

it('refuses a non-Admin grant server-side with 403 (AC #4, AD-6)', function () {
    $user = User::factory()->serving()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();

    $this->actingAs($user)->post('/settings/staff-grants', [
        'user_id' => $target->id,
        'unit_id' => $unit->id,
        'reason' => 'S1 staff, no line billet',
    ])->assertForbidden();

    expect(PortalRoleGrant::count())->toBe(0);
});

it('requires authentication to view or author grants', function () {
    $this->get('/settings/staff-grants')->assertRedirect(route('login'));

    $this->post('/settings/staff-grants', [
        'user_id' => 1,
        'unit_id' => 1,
        'reason' => 'reason',
    ])->assertRedirect(route('login'));
});

it('422s a missing user_id', function () {
    $admin = User::factory()->admin()->create();
    $unit = Unit::factory()->create();

    $this->actingAs($admin)->post('/settings/staff-grants', [
        'unit_id' => $unit->id,
        'reason' => 'reason',
    ])->assertInvalid('user_id');

    expect(PortalRoleGrant::count())->toBe(0);
});

it('422s a missing unit_id', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    $this->actingAs($admin)->post('/settings/staff-grants', [
        'user_id' => $target->id,
        'reason' => 'reason',
    ])->assertInvalid('unit_id');

    expect(PortalRoleGrant::count())->toBe(0);
});

it('422s a missing reason (AD-16)', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();

    $this->actingAs($admin)->post('/settings/staff-grants', [
        'user_id' => $target->id,
        'unit_id' => $unit->id,
    ])->assertInvalid('reason');

    expect(PortalRoleGrant::count())->toBe(0);
});

it('422s a whitespace-only reason — Laravel\'s `required` rule already trims (AD-16)', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();

    $this->actingAs($admin)->post('/settings/staff-grants', [
        'user_id' => $target->id,
        'unit_id' => $unit->id,
        'reason' => '   ',
    ])->assertInvalid('reason');

    expect(PortalRoleGrant::count())->toBe(0);
});

it('422s a grant targeting a user who already holds the admin flag (AD-7)', function () {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();
    $unit = Unit::factory()->create();

    $this->actingAs($admin)->post('/settings/staff-grants', [
        'user_id' => $otherAdmin->id,
        'unit_id' => $unit->id,
        'reason' => 'reason',
    ])->assertInvalid('user_id');

    expect(PortalRoleGrant::count())->toBe(0);
});

it('lets an Admin revoke a grant (AC #2)', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();
    $grant = PortalRoleGrant::factory()->create(['user_id' => $target->id, 'unit_id' => $unit->id]);

    $this->actingAs($admin)->delete("/settings/staff-grants/{$grant->id}", [
        'reason' => 'no longer staff',
    ])->assertRedirect();

    expect(PortalRoleGrant::find($grant->id))->toBeNull();
});

it('refuses a non-Admin revoke server-side with 403 (AD-6)', function () {
    $user = User::factory()->serving()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();
    $grant = PortalRoleGrant::factory()->create(['user_id' => $target->id, 'unit_id' => $unit->id]);

    $this->actingAs($user)->delete("/settings/staff-grants/{$grant->id}", [
        'reason' => 'no longer staff',
    ])->assertForbidden();

    expect(PortalRoleGrant::find($grant->id))->not->toBeNull();
});

it('422s a revoke with no reason (AD-16)', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    $unit = Unit::factory()->create();
    $grant = PortalRoleGrant::factory()->create(['user_id' => $target->id, 'unit_id' => $unit->id]);

    $this->actingAs($admin)->delete("/settings/staff-grants/{$grant->id}", [])
        ->assertInvalid('reason');

    expect(PortalRoleGrant::find($grant->id))->not->toBeNull();
});

it('reflects a revoke in the target\'s effective scope on the very next request (AC #2)', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->serving()->create();
    $unit = Unit::factory()->create();
    $grant = PortalRoleGrant::factory()->create(['user_id' => $target->id, 'unit_id' => $unit->id]);

    expect(app(ScopeResolver::class)->resolve($target))->toContain($unit->id);

    $this->actingAs($admin)->delete("/settings/staff-grants/{$grant->id}", [
        'reason' => 'no longer staff',
    ])->assertRedirect();

    expect(app(ScopeResolver::class)->resolve($target->fresh()))->not->toContain($unit->id);
});
