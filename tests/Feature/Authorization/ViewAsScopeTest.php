<?php

use App\Authorization\ScopeResolver;
use App\Authorization\ViewAs;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;

// AD-9: effective scope = min(real authority, view-as target), re-checked on every call. Each
// scenario sets a stored target the way the switcher would, then asks the resolver the question
// every scoped read asks: "which units may this user read right now".

/**
 * 7CAV → 1-7 → A/1-7 → 1/A/1-7 → {1/1/A/1-7, 2/1/A/1-7}, plus a sibling battalion 2-7.
 *
 * @return array<string, Unit>
 */
function vaTree(): array
{
    $repo = app(UnitRepository::class);
    $regiment = $repo->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $repo->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $repo->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
    $platoon = $repo->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $section1 = $repo->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);
    $section2 = $repo->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $platoon->id);
    $otherBattalion = $repo->create(UnitType::Battalion, '2nd Battalion', '2-7', $regiment->id);

    return compact('regiment', 'battalion', 'company', 'platoon', 'section1', 'section2', 'otherBattalion');
}

/** A user whose synced billet commands `$unit`. */
function vaLeaderOf(Unit $unit, int $cavId, ?string $rank = 'SGT', string $username = 'Vasquez.R'): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $cavId + 10000]);
    Trooper::factory()->create([
        'cav_id' => $cavId, 'cav_position_id' => $cavId + 10000, 'status' => TrooperStatus::Combat, 'rank' => $rank,
    ]);

    return User::factory()->create(['cav_id' => $cavId, 'username' => $username]);
}

function vaViewAs(User $viewer, ViewAsKind $kind, int $id): void
{
    app(ViewAs::class)->enter($viewer, new ViewAsTarget($kind, $id));
}

/** @param  array<int, Unit>  $units */
function vaIds(array $units): array
{
    return array_map(fn (Unit $unit) => $unit->id, $units);
}

it('narrows a leader to the subtree of the unit they view as (AC #2)', function () {
    $tree = vaTree();
    $commander = vaLeaderOf($tree['company'], 100);

    vaViewAs($commander, ViewAsKind::Unit, $tree['platoon']->id);

    expect(app(ScopeResolver::class)->resolve($commander)->all())
        ->toEqualCanonicalizing(vaIds([$tree['platoon'], $tree['section1'], $tree['section2']]));
});

it('keeps the real-authority ceiling available alongside the narrowed scope', function () {
    $tree = vaTree();
    $commander = vaLeaderOf($tree['company'], 100);
    vaViewAs($commander, ViewAsKind::Unit, $tree['section1']->id);

    $resolver = app(ScopeResolver::class);

    expect($resolver->resolve($commander)->all())->toBe([$tree['section1']->id])
        ->and($resolver->realScope($commander))->toHaveCount(4);
});

it('refuses a unit above or outside real authority and falls back to real scope (AC #3)', function (string $target) {
    $tree = vaTree();
    $platoonLeader = vaLeaderOf($tree['platoon'], 100);
    $resolver = app(ScopeResolver::class);

    expect($resolver->evaluate($platoonLeader, new ViewAsTarget(ViewAsKind::Unit, $tree[$target]->id)))->toBeNull();

    // Even if the session were forged to hold it, the resolver never widens.
    vaViewAs($platoonLeader, ViewAsKind::Unit, $tree[$target]->id);

    expect($resolver->activeViewAs($platoonLeader))->toBeNull()
        ->and($resolver->resolve($platoonLeader)->all())
        ->toEqualCanonicalizing(vaIds([$tree['platoon'], $tree['section1'], $tree['section2']]));
})->with(['above: their company' => 'company', 'above: the regiment' => 'regiment', 'outside: a sibling battalion' => 'otherBattalion']);

it('refuses a unit id that does not exist', function () {
    $tree = vaTree();
    $commander = vaLeaderOf($tree['company'], 100);

    expect(app(ScopeResolver::class)->evaluate($commander, new ViewAsTarget(ViewAsKind::Unit, 999_999)))->toBeNull();
});

it('re-checks a stored target against current authority on every call (AC #4)', function () {
    $tree = vaTree();
    $staff = User::factory()->create();
    $grant = PortalRoleGrant::factory()->create(['user_id' => $staff->id, 'unit_id' => $tree['platoon']->id]);
    vaViewAs($staff, ViewAsKind::Unit, $tree['section2']->id);
    $resolver = app(ScopeResolver::class);

    expect($resolver->resolve($staff)->all())->toBe([$tree['section2']->id]);

    // The grant that made the target legal is revoked: the stored target stops applying at once,
    // and with no real authority left the user sees nothing at all.
    $grant->delete();

    expect($resolver->activeViewAs($staff))->toBeNull()
        ->and($resolver->resolve($staff)->all())->toBe([]);
});

it('restores the full real scope once view-as is cleared (AC #5)', function () {
    $tree = vaTree();
    $commander = vaLeaderOf($tree['company'], 100);
    vaViewAs($commander, ViewAsKind::Unit, $tree['section1']->id);

    app(ViewAs::class)->exit();

    expect(app(ScopeResolver::class)->resolve($commander))->toHaveCount(4);
});

it('lets a leaf leader view as only their own section, which changes nothing (AC #6)', function () {
    $tree = vaTree();
    $sectionLeader = vaLeaderOf($tree['section1'], 100);
    $resolver = app(ScopeResolver::class);

    expect($resolver->realScope($sectionLeader)->all())->toBe([$tree['section1']->id])
        ->and($resolver->evaluate($sectionLeader, new ViewAsTarget(ViewAsKind::Unit, $tree['section2']->id)))->toBeNull()
        ->and($resolver->evaluate($sectionLeader, new ViewAsTarget(ViewAsKind::Unit, $tree['section1']->id))?->scope->all())
        ->toBe([$tree['section1']->id]);
});

it('lets an Admin view as a person, seeing exactly that person\'s derived scope', function () {
    $tree = vaTree();
    $admin = User::factory()->admin()->create();
    $leader = vaLeaderOf($tree['section2'], 100);
    vaViewAs($admin, ViewAsKind::User, $leader->id);

    $active = app(ScopeResolver::class)->activeViewAs($admin);

    expect(app(ScopeResolver::class)->resolve($admin)->all())->toBe([$tree['section2']->id])
        ->and($active->toArray())->toMatchArray([
            'kind' => 'user',
            'id' => $leader->id,
            'label' => 'SGT Vasquez.R',
            'designation' => '2/1/A/1-7',
            'unitName' => '2nd Section',
            'admin' => false,
        ]);
});

it('previews a person with no scope as seeing nothing, not as the Admin\'s full tree', function () {
    vaTree();
    $admin = User::factory()->admin()->create();
    $trooper = User::factory()->create(['username' => 'Doyle.K']);
    vaViewAs($admin, ViewAsKind::User, $trooper->id);

    expect(app(ScopeResolver::class)->resolve($admin)->all())->toBe([])
        ->and(app(ScopeResolver::class)->activeViewAs($admin)->label)->toBe('Doyle.K');
});

it('refuses person view-as for a non-Admin, even for someone inside their own scope', function () {
    $tree = vaTree();
    $commander = vaLeaderOf($tree['company'], 100, username: 'Hale.S');
    $sectionLeader = vaLeaderOf($tree['section1'], 200);
    vaViewAs($commander, ViewAsKind::User, $sectionLeader->id);

    expect(app(ScopeResolver::class)->activeViewAs($commander))->toBeNull()
        ->and(app(ScopeResolver::class)->resolve($commander))->toHaveCount(4);
});

it('refuses an Admin viewing as themselves or a missing user', function () {
    $admin = User::factory()->admin()->create();
    $resolver = app(ScopeResolver::class);

    expect($resolver->evaluate($admin, new ViewAsTarget(ViewAsKind::User, $admin->id)))->toBeNull()
        ->and($resolver->evaluate($admin, new ViewAsTarget(ViewAsKind::User, 999_999)))->toBeNull();
});

it('drops a person preview the moment the viewer stops being an Admin', function () {
    $tree = vaTree();
    $admin = User::factory()->admin()->create();
    $leader = vaLeaderOf($tree['section2'], 100);
    vaViewAs($admin, ViewAsKind::User, $leader->id);

    $admin->forceFill(['is_admin' => false])->save();

    expect(app(ScopeResolver::class)->activeViewAs($admin))->toBeNull();
});

it('never applies one user\'s stored target to anyone else\'s scope', function () {
    $tree = vaTree();
    $commander = vaLeaderOf($tree['company'], 100, username: 'Hale.S');
    $otherCommander = vaLeaderOf($tree['company'], 200, username: 'Brennan.J');
    vaViewAs($commander, ViewAsKind::Unit, $tree['section1']->id);

    expect(app(ScopeResolver::class)->resolve($otherCommander))->toHaveCount(4)
        ->and(app(ViewAs::class)->targetFor($otherCommander))->toBeNull();
});

it('ignores a session value it did not write', function (Closure $stored) {
    $user = User::factory()->create();
    session()->put('view_as', $stored($user->id));

    expect(app(ViewAs::class)->targetFor($user))->toBeNull();
})->with([
    'a scalar' => fn () => fn (int $viewer) => 'unit:3',
    'an unknown kind' => fn () => fn (int $viewer) => ['viewer' => $viewer, 'kind' => 'regiment', 'id' => 1],
    'a string id' => fn () => fn (int $viewer) => ['viewer' => $viewer, 'kind' => 'unit', 'id' => '1'],
    'no viewer' => fn () => fn (int $viewer) => ['kind' => 'unit', 'id' => 1],
]);
