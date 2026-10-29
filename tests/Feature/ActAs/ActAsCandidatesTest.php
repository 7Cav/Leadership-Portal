<?php

use App\Authorization\ActAs;
use App\Authorization\ScopeResolver;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitActingLeadRepository;
use App\Repositories\UnitRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// #496: the act as picker reads everyone's standing and reach in bulk, a fixed number of queries whatever the size of
// the leader's command, and lists exactly who the per-person check (ActAs::allows(), still read one person at a time
// when a choice is made) would accept. Every user of a varied tree is checked as the leader.
//
//   7th Cavalry Regiment
//     Alpha Company (CO 7000)                              Bravo Company (CO 7001)
//       1st Platoon (PL 7100)    2nd Platoon (acting lead)    1st Platoon (PL 7102)
//         1st Section (SL 7200)    1st Section (SL 7202)        1st Section (SL 7203)
//         2nd Section (SL 7201)    2nd Section (SL 7204)
//                                  3rd Section (SL 7205)

function aacPerson(int $cavId, string $username, ?int $position, ?Unit $placedIn = null, ?TrooperStatus $status = null, array $user = []): User
{
    Trooper::factory()->create([
        'cav_id' => $cavId, 'cav_position_id' => $position, 'status' => $status ?? TrooperStatus::Combat,
        'username' => $username, 'primary_section_id' => $placedIn?->id,
    ]);

    return User::factory()->create(['cav_id' => $cavId, 'username' => $username] + $user);
}

function aacMap(Unit $unit, int $position): void
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => $position]);
}

function aacActingLead(User $user, Unit $unit): void
{
    app(UnitActingLeadRepository::class)->assign($unit->id, Trooper::where('cav_id', $user->cav_id)->value('id'), null);
}

/**
 * Who the picker must list for `$actor`, by the per-person rule: nobody when no picker is offered, otherwise every user
 * {@see ActAs::allows()} accepts through what `$actor` acts as now, in the picker's (username) order.
 *
 * @return list<int>
 */
function aacExpected(User $actor): array
{
    $actAs = app(ActAs::class);
    if (! $actAs->offers($actor)) {
        return [];
    }

    $via = $actAs->viaFor($actor);

    return app(UserRepository::class)->all()
        ->filter(fn (User $user) => $actAs->allows($actor, $user, $via))
        ->pluck('id')
        ->values()
        ->all();
}

/** @return list<int> */
function aacListed(User $actor): array
{
    return app(ActAs::class)->candidates($actor)->pluck('id')->values()->all();
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->alpha = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->regiment->id);
    $this->a1 = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->alpha->id);
    $this->a11 = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->a1->id);
    $this->a12 = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->a1->id);
    $this->a2 = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->alpha->id);
    $this->a21 = $units->create(UnitType::Section, '1st Section', '1/2/A/1-7', $this->a2->id);
    $this->a22 = $units->create(UnitType::Section, '2nd Section', '2/2/A/1-7', $this->a2->id);
    $this->a23 = $units->create(UnitType::Section, '3rd Section', '3/2/A/1-7', $this->a2->id);
    $this->bravo = $units->create(UnitType::Company, 'Bravo Company', 'B/1-7', $this->regiment->id);
    $this->b1 = $units->create(UnitType::Platoon, '1st Platoon', '1/B/1-7', $this->bravo->id);
    $this->b11 = $units->create(UnitType::Section, '1st Section', '1/1/B/1-7', $this->b1->id);

    foreach ([7000 => $this->alpha, 7001 => $this->bravo, 7100 => $this->a1, 7102 => $this->b1, 7200 => $this->a11, 7201 => $this->a12, 7202 => $this->a21, 7203 => $this->b11, 7204 => $this->a22, 7205 => $this->a23] as $position => $unit) {
        aacMap($unit, $position);
    }

    $this->coA = aacPerson(800, 'Okafor.T', 7000);
    $this->coB = aacPerson(801, 'Brandt.K', 7001);
    $this->plA1 = aacPerson(802, 'Hale.S', 7100);
    $this->plB1 = aacPerson(803, 'Ward.N', 7102);
    $this->slA11 = aacPerson(804, 'Vasquez.R', 7200, $this->a11);
    // A grant beyond anyone below Alpha's platoon leader, inside Alpha.
    $this->slA12 = aacPerson(805, 'Mercer.J', 7201, $this->a12);
    PortalRoleGrant::factory()->create(['user_id' => $this->slA12->id, 'unit_id' => $this->a2->id]);
    // A grant inside Bravo's platoon: fine for Bravo's leaders.
    $this->slB11 = aacPerson(806, 'Lindqvist.E', 7203, $this->b11);
    PortalRoleGrant::factory()->create(['user_id' => $this->slB11->id, 'unit_id' => $this->b11->id]);
    // Acting lead of Alpha's leaderless 2nd Platoon, placed in its 1st Section: its Section Leader commands them.
    $this->actingA2 = aacPerson(807, 'Acting.A', null, $this->a21);
    aacActingLead($this->actingA2, $this->a2);
    $this->slA21 = aacPerson(808, 'Novak.P', 7202, $this->a21);
    // A Section Leader who is also acting lead of the section beside theirs.
    $this->slA22 = aacPerson(809, 'Reyes.D', 7204, $this->a22);
    aacActingLead($this->slA22, $this->a21);
    $this->slA23 = aacPerson(816, 'Quinn.Q', 7205, $this->a23);
    // Left the Cav while still on a billet below Alpha, and a grant holder who left too.
    $this->gone = aacPerson(810, 'Gone.G', 7201, null, TrooperStatus::Inactive);
    $this->goneGranted = aacPerson(811, 'Lapsed.L', 7203, null, TrooperStatus::Inactive);
    PortalRoleGrant::factory()->create(['user_id' => $this->goneGranted->id, 'unit_id' => $this->regiment->id]);
    // On a reserve standing, still serving, as a peer on Bravo's section billet.
    $this->reserve = aacPerson(812, 'Peer.P', 7203, $this->b11, TrooperStatus::Reserve);
    // An Admin on a billet below, a staff grant holder with no billet, a member, an unlinked user.
    $this->adminBillet = aacPerson(813, 'Admin.B', 7200, $this->a11, null, ['is_admin' => true]);
    $this->staff = aacPerson(814, 'Staff.S', null);
    PortalRoleGrant::factory()->create(['user_id' => $this->staff->id, 'unit_id' => $this->alpha->id]);
    $this->member = aacPerson(815, 'Member.M', null, $this->a12);
    $this->unlinked = User::factory()->create(['cav_id' => null, 'username' => 'Unlinked.U']);
    $this->admin = User::factory()->admin()->create(['username' => 'Root.R']);
});

it('lists exactly who the per-person rule accepts, for every user as the leader', function () {
    $actAs = app(ActAs::class);
    $listedAny = false;

    foreach (User::all() as $actor) {
        $actAs->stop();

        expect(aacListed($actor))->toBe(aacExpected($actor), "as {$actor->username}");
        $listedAny = $listedAny || aacListed($actor) !== [];
    }

    expect($listedAny)->toBeTrue();
});

it('lists the serving billet holders below, never anyone whose command covers the leader\'s own placement (#455)', function () {
    // In username order. An acting lead holds no billet, so is never listed; a grant or acting lead inside the leader's
    // command is fine, one beyond it is not; nobody who left, no Admin.
    expect(aacListed($this->coA))->toBe(collect([$this->plA1, $this->slA12, $this->slA21, $this->slA23, $this->slA22, $this->slA11])->pluck('id')->all())
        ->and(aacListed($this->plA1))->toBe([$this->slA11->id])
        // Placed in the 1st Section of the platoon they lead: its Section Leader, and the one acting lead of it, command them.
        ->and(aacListed($this->actingA2))->toBe([$this->slA23->id])
        ->and(aacListed($this->coB))->toBe(collect([$this->slB11, $this->reserve, $this->plB1])->pluck('id')->all());
});

it('gives an Admin acting as a leader the list that leader gets, and nothing once the chain would grow', function (string $leader) {
    $actAs = app(ActAs::class);
    $actAs->start($this->admin, new ViewAsTarget(ViewAsKind::User, $this->{$leader}->id));

    expect(aacListed($this->admin))->toBe(aacListed($this->{$leader}))
        ->and(aacListed($this->admin))->toBe(aacExpected($this->admin));

    $first = aacListed($this->admin);
    if ($first !== []) {
        $actAs->start($this->admin, new ViewAsTarget(ViewAsKind::User, $first[0]), new ViewAsTarget(ViewAsKind::User, $this->{$leader}->id));

        expect(aacListed($this->admin))->toBe([])->and(aacExpected($this->admin))->toBe([]);
    }
})->with(['a commander' => ['coA'], 'a platoon leader' => ['plA1'], 'an acting lead' => ['actingA2'], 'the other commander' => ['coB']]);

it('gives an Admin acting as a billet the billet holders below that unit', function () {
    app(ActAs::class)->start($this->admin, new ViewAsTarget(ViewAsKind::Unit, $this->alpha->id));

    expect(aacListed($this->admin))->toBe(aacExpected($this->admin))
        ->and(aacListed($this->admin))->toContain($this->slA21->id);
});

it('works out every user\'s real scope in bulk exactly as one at a time', function () {
    $scope = app(ScopeResolver::class);
    $users = User::all();
    $bulk = $scope->realScopes($users);

    foreach ($users as $user) {
        $ids = fn (Collection $scope) => $scope->map(fn ($id) => (int) $id)->sort()->values()->all();

        expect($ids($bulk->get($user->id)))->toBe($ids($scope->realScope($user)), "for {$user->username}");
    }
});

it('reads the picker in the same number of queries for a small unit and a large one', function () {
    $count = function (User $leader): int {
        app(ActAs::class)->stop();
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ActAs::class)->candidates($leader);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $small = $count($this->plB1);
    $before = aacListed($this->plB1);

    // Twenty more sections under the same platoon, each with a serving Section Leader, half with a grant and an acting
    // lead of their own section, and a departed holder on every other billet.
    $units = app(UnitRepository::class);
    foreach (range(1, 20) as $n) {
        $section = $units->create(UnitType::Section, "Section {$n}", "{$n}/1/B/1-7", $this->b1->id);
        aacMap($section, 7300 + $n);
        $leader = aacPerson(900 + $n, "Leader.{$n}", 7300 + $n, $section);
        if ($n % 2 === 0) {
            PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $section->id]);
            aacActingLead($leader, $section);
            aacPerson(950 + $n, "Left.{$n}", 7300 + $n, $section, TrooperStatus::Inactive);
        }
    }

    expect(aacListed($this->plB1))->toHaveCount(count($before) + 20)
        ->and($count($this->plB1))->toBe($small)
        // A fixed number whatever the size; one more since Admin via billet reads the approved billets for everyone, and
        // one more for the units everyone oversees (2026-10-29).
        ->and($small)->toBeLessThanOrEqual(17);
});
