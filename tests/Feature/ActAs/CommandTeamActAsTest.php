<?php

use App\Authorization\ActAs;
use App\Models\AuditLogEntry;
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
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// Inside a unit's command team a senior may act as everyone junior to them in that
// team, at every echelon, by the roster's command team order (CO, XO, then the senior NCO; PL then PSG; SL then ASL).
// Never upward, never a peer, never anyone in another unit's team. Each holder is placed in the unit their billet
// commands, as the sync places them, so the junior's command covers the senior's placement.
//
//   7th Cavalry Regiment (RCO, RXO, RCSM)
//     1-7 (CO, XO, SGM)                                       DEVCOM (1IC, 2IC)
//       Alpha Company (CO, XO, 1SG)       Bravo Company (CO)
//         1st Platoon (PL, PSG)             2nd Platoon (PSG, acting lead)
//           1st Section (SL, ASL)
//           2nd Section (SL, ASL)

function ctPerson(int $cavId, string $username, ?int $position, ?string $billet, ?Unit $placedIn, array $user = []): User
{
    $trooper = Trooper::factory()->create([
        'cav_id' => $cavId, 'cav_position_id' => $position, 'billet' => $billet, 'status' => TrooperStatus::Combat,
        'rank' => 'SGT', 'username' => $username, 'primary_section_id' => $placedIn?->id,
    ]);

    return User::factory()->create(['cav_id' => $cavId, 'forum_user_id' => $trooper->forum_user_id, 'username' => $username] + $user);
}

/** A billet holder: their billet maps to `$unit` (the position is the cav id plus 7000) and they are placed in it. */
function ctHolder(int $cavId, string $username, string $billet, Unit $unit): User
{
    UnitCommandMapping::factory()->create(['unit_id' => $unit->id, 'cav_position_id' => 7000 + $cavId]);

    return ctPerson($cavId, $username, 7000 + $cavId, $billet, $unit);
}

function ctStarts(object $test, User $leader, User $as): void
{
    $test->actingAs($leader)->post('/act-as', ['kind' => 'user', 'id' => $as->id])->assertRedirect('/home');
    $test->actingAs($leader)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $as->id));
}

function ctRefused(object $test, User $leader, User $as): void
{
    $response = $test->actingAs($leader)->post('/act-as', ['kind' => 'user', 'id' => $as->id]);
    expect($response->status())->toBeIn([302, 403]);
    if ($response->status() === 302) {
        $response->assertSessionHasErrors('act_as');
    }

    expect(AuditLogEntry::where('action', 'act_as.started')->count())->toBe(0);
    $test->actingAs($leader)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $leader->id)->where('actingAs', null));
}

/** @return list<int> */
function ctListed(User $actor): array
{
    return app(ActAs::class)->candidates($actor)->pluck('id')->sort()->values()->all();
}

/**
 * @param  list<User>  $users
 * @return list<int>
 */
function ctIds(array $users): array
{
    return collect($users)->pluck('id')->sort()->values()->all();
}

beforeEach(function () {
    $units = app(UnitRepository::class);
    $this->regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $this->battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $this->regiment->id);
    $this->devcom = $units->create(UnitType::Battalion, 'DEVCOM', 'DEVCOM', $this->regiment->id);
    $this->alpha = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $this->battalion->id);
    $this->bravo = $units->create(UnitType::Company, 'Bravo Company', 'B/1-7', $this->battalion->id);
    $this->platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $this->alpha->id);
    $this->platoon2 = $units->create(UnitType::Platoon, '2nd Platoon', '2/A/1-7', $this->alpha->id);
    $this->section1 = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $this->platoon->id);
    $this->section2 = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $this->platoon->id);

    $this->rco = ctHolder(100, 'Rco.R', 'Regimental Commanding Officer', $this->regiment);
    $this->rxo = ctHolder(101, 'Rxo.R', 'Regimental Executive Officer', $this->regiment);
    $this->rcsm = ctHolder(102, 'Rcsm.R', 'Regimental Command Sergeant Major', $this->regiment);
    $this->bnco = ctHolder(110, 'Bnco.B', '1-7 Commanding Officer', $this->battalion);
    $this->bnxo = ctHolder(111, 'Bnxo.B', '1-7 Executive Officer', $this->battalion);
    $this->bnsgm = ctHolder(112, 'Bnsgm.B', '1-7 Sergeant Major', $this->battalion);
    $this->ic1 = ctHolder(120, 'First.D', 'DEVCOM 1IC', $this->devcom);
    $this->ic2 = ctHolder(121, 'Second.D', 'DEVCOM 2IC', $this->devcom);
    $this->coA = ctHolder(130, 'Coa.A', 'Commander A/1-7', $this->alpha);
    $this->xoA = ctHolder(131, 'Xoa.A', 'Executive Officer A/1-7', $this->alpha);
    $this->sgA = ctHolder(132, 'Firstsgt.A', 'First Sergeant A/1-7', $this->alpha);
    $this->coB = ctHolder(140, 'Cob.B', 'Commander B/1-7', $this->bravo);
    $this->pl = ctHolder(150, 'Pl.P', 'Platoon Leader 1/A/1-7', $this->platoon);
    $this->psg = ctHolder(151, 'Psg.P', 'Platoon Sergeant 1/A/1-7', $this->platoon);
    $this->psg2 = ctHolder(152, 'Psgtwo.P', 'Platoon Sergeant 2/A/1-7', $this->platoon2);
    $this->sl1 = ctHolder(160, 'Slone.S', 'Section Leader 1/1/A/1-7', $this->section1);
    $this->asl1 = ctHolder(161, 'Aslone.S', 'Assistant Section Leader 1/1/A/1-7', $this->section1);
    $this->sl2 = ctHolder(170, 'Sltwo.S', 'Section Leader 2/1/A/1-7', $this->section2);
    $this->asl2 = ctHolder(171, 'Asltwo.S', 'Assistant Section Leader 2/1/A/1-7', $this->section2);

    // The 2nd Platoon has no Platoon Leader: an Admin made a trooper placed in it its acting lead.
    $this->actingPl = ctPerson(180, 'Acting.A', null, 'Rifleman', $this->platoon2);
    app(UnitActingLeadRepository::class)->assign($this->platoon2->id, Trooper::where('cav_id', 180)->value('id'), null);
});

describe('a senior in a command team', function () {
    it('acts as each billet junior to theirs in the same unit, recorded as for any act as', function (string $senior, string $junior) {
        ctStarts($this, $this->{$senior}, $this->{$junior});

        $entry = AuditLogEntry::where('action', 'act_as.started')->sole();
        expect($entry->actor_id)->toBe($this->{$senior}->id)
            ->and($entry->target_id)->toBe((string) $this->{$junior}->id)
            ->and($entry->on_behalf_of_id)->toBeNull();
    })->with([
        'RCO as RXO' => ['rco', 'rxo'],
        'RCO as RCSM' => ['rco', 'rcsm'],
        'RXO as RCSM' => ['rxo', 'rcsm'],
        'battalion CO as XO' => ['bnco', 'bnxo'],
        'battalion CO as SGM' => ['bnco', 'bnsgm'],
        'battalion XO as SGM' => ['bnxo', 'bnsgm'],
        'DEVCOM 1IC as 2IC' => ['ic1', 'ic2'],
        'company CO as XO' => ['coA', 'xoA'],
        'company CO as 1SG' => ['coA', 'sgA'],
        'company XO as 1SG' => ['xoA', 'sgA'],
        'PL as PSG' => ['pl', 'psg'],
        'SL as ASL' => ['sl1', 'asl1'],
        'acting PL as the PSG of the platoon they lead' => ['actingPl', 'psg2'],
    ]);

    it('never acts as a senior in their own team', function (string $junior, string $senior) {
        ctRefused($this, $this->{$junior}, $this->{$senior});
    })->with([
        'RXO as RCO' => ['rxo', 'rco'],
        'RCSM as RCO' => ['rcsm', 'rco'],
        'RCSM as RXO' => ['rcsm', 'rxo'],
        'battalion XO as CO' => ['bnxo', 'bnco'],
        'battalion SGM as CO' => ['bnsgm', 'bnco'],
        'battalion SGM as XO' => ['bnsgm', 'bnxo'],
        'DEVCOM 2IC as 1IC' => ['ic2', 'ic1'],
        'company XO as CO' => ['xoA', 'coA'],
        'company 1SG as CO' => ['sgA', 'coA'],
        'company 1SG as XO' => ['sgA', 'xoA'],
        'PSG as PL' => ['psg', 'pl'],
        'ASL as SL' => ['asl1', 'sl1'],
    ]);

    it('never acts as a peer in another unit\'s team, nor as anyone whose unit is above theirs', function (string $leader, string $other) {
        ctRefused($this, $this->{$leader}, $this->{$other});
    })->with([
        'an SL as the other section\'s SL' => ['sl1', 'sl2'],
        'an SL as the other section\'s ASL' => ['sl1', 'asl2'],
        'a company CO as the other company\'s CO' => ['coA', 'coB'],
        'a company XO as the other company\'s CO' => ['xoA', 'coB'],
        'the 1IC as the battalion XO' => ['ic1', 'bnxo'],
        'an SL as the PL above them' => ['sl1', 'pl'],
        'an SL as the PSG above them' => ['sl1', 'psg'],
        'a PSG as the company XO above them' => ['psg', 'xoA'],
        'a company CO as the battalion SGM above them' => ['coA', 'bnsgm'],
        'a battalion CO as the RCSM above them' => ['bnco', 'rcsm'],
    ]);

    it('refuses a junior whose own reach goes outside the unit, and allows one whose reach stays inside it', function () {
        PortalRoleGrant::factory()->create(['user_id' => $this->psg->id, 'unit_id' => $this->alpha->id]);
        ctRefused($this, $this->pl, $this->psg);

        PortalRoleGrant::query()->delete();
        PortalRoleGrant::factory()->create(['user_id' => $this->psg->id, 'unit_id' => $this->section2->id]);
        ctStarts($this, $this->pl, $this->psg);
    });

    it('refuses a junior on the same billet title as theirs, and a holder whose title names no team billet', function () {
        $peer = ctHolder(190, 'Peer.P', 'Platoon Leader 1/A/1-7', $this->platoon);
        $untitled = ctHolder(191, 'Clerk.C', 'Platoon Clerk 1/A/1-7', $this->platoon);

        ctRefused($this, $this->pl, $peer);
        ctRefused($this, $this->pl, $untitled);
    });

    it('drops the choice on the next request once the junior is promoted over them', function () {
        ctStarts($this, $this->sl1, $this->asl1);
        Trooper::where('cav_id', 161)->update(['billet' => 'Section Leader 1/1/A/1-7']);

        $this->actingAs($this->sl1)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->sl1->id)->where('actingAs', null));
    });

    it('does not chain: the RCO acting as the RXO must stop before acting as the RCSM', function () {
        ctStarts($this, $this->rco, $this->rxo);

        $this->actingAs($this->rco)->post('/act-as', ['kind' => 'user', 'id' => $this->rcsm->id])->assertSessionHasErrors('act_as');
        $this->actingAs($this->rco)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->rxo->id));
    });
});

describe('an Admin', function () {
    beforeEach(function () {
        $this->admin = User::factory()->admin()->create(['username' => 'Root.R']);
    });

    it('acting as the RCO may act as the RXO, as for anyone that leader may act as, one step only', function () {
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->rco->id])->assertRedirect('/home');
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->rxo->id])->assertRedirect('/home');

        $this->actingAs($this->admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->rxo->id)->where('actingAs.admin', 'Root.R'));
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->rcsm->id])->assertSessionHasErrors('act_as');
    });

    it('acting as the RXO may not act as the RCO', function () {
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->rxo->id])->assertRedirect('/home');

        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->rco->id])->assertSessionHasErrors('act_as');
        $this->actingAs($this->admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->rxo->id));
    });

    it('acting as the regiment\'s billet keeps today\'s rule: only billet holders below the regiment', function () {
        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'unit', 'id' => $this->regiment->id])->assertRedirect('/home');

        $this->actingAs($this->admin)->post('/act-as', ['kind' => 'user', 'id' => $this->rxo->id])->assertSessionHasErrors('act_as');
    });
});

describe('the picker', function () {
    it('lists exactly the juniors in the leader\'s team and the billet holders below', function () {
        $belowPlatoon = [$this->sl1, $this->asl1, $this->sl2, $this->asl2];

        expect(ctListed($this->sl1))->toBe(ctIds([$this->asl1]))
            ->and(ctListed($this->sl2))->toBe(ctIds([$this->asl2]))
            ->and(ctListed($this->pl))->toBe(ctIds([$this->psg, ...$belowPlatoon]))
            ->and(ctListed($this->psg))->toBe(ctIds($belowPlatoon))
            ->and(ctListed($this->coA))->toBe(ctIds([$this->xoA, $this->sgA, $this->pl, $this->psg, $this->psg2, ...$belowPlatoon]))
            ->and(ctListed($this->sgA))->toBe(ctIds([$this->pl, $this->psg, $this->psg2, ...$belowPlatoon]))
            ->and(ctListed($this->actingPl))->toBe(ctIds([$this->psg2]))
            ->and(ctListed($this->ic1))->toBe(ctIds([$this->ic2]));

        $everyoneBelowRegiment = User::query()->whereNotIn('id', [$this->rco->id, $this->rxo->id, $this->rcsm->id, $this->actingPl->id])->pluck('id')->sort()->values()->all();
        expect(ctListed($this->rco))->toBe(ctIds([$this->rxo, $this->rcsm, ...User::query()->whereIn('id', $everyoneBelowRegiment)->get()->all()]))
            ->and(ctListed($this->rxo))->toBe(ctIds([$this->rcsm, ...User::query()->whereIn('id', $everyoneBelowRegiment)->get()->all()]))
            ->and(ctListed($this->rcsm))->toBe($everyoneBelowRegiment);
    });

    it('lists exactly who the per-person rule accepts, for every user', function () {
        $actAs = app(ActAs::class);

        foreach (User::all() as $actor) {
            $actAs->stop();
            $expected = $actAs->offers($actor)
                ? app(UserRepository::class)->all()->filter(fn (User $user) => $actAs->allows($actor, $user))->pluck('id')->sort()->values()->all()
                : [];

            expect(ctListed($actor))->toBe($expected, "as {$actor->username}");
        }
    });

    it('is offered to a Section Leader with an ASL, and not to the ASL', function () {
        $this->actingAs($this->sl1)->get('/home')->assertInertia(fn (Assert $page) => $page->where('canActAs', true));
        $this->actingAs($this->sl1)->getJson('/act-as/options')->assertOk()->assertExactJson([
            'people' => [['id' => $this->asl1->id, 'label' => 'SGT Aslone.S', 'designation' => '1/1/A/1-7']],
        ]);

        $this->actingAs($this->asl1)->get('/home')->assertInertia(fn (Assert $page) => $page->where('canActAs', false));
        $this->actingAs($this->asl1)->getJson('/act-as/options')->assertForbidden();
        $this->actingAs($this->asl1)->post('/act-as', ['kind' => 'user', 'id' => $this->sl1->id])->assertForbidden();
    });

    it('is not offered to a Section Leader whose ASL billet is vacant', function () {
        Trooper::where('cav_id', 161)->update(['status' => TrooperStatus::Inactive]);

        $this->actingAs($this->sl1)->get('/home')->assertInertia(fn (Assert $page) => $page->where('canActAs', false));
        $this->actingAs($this->sl1)->getJson('/act-as/options')->assertForbidden();
    });

    it('reads in the same number of queries for a small command team and a large command', function () {
        $count = function (User $leader): int {
            app(ActAs::class)->stop();
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(ActAs::class)->candidates($leader);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $small = $count($this->pl);
        $before = ctListed($this->pl);

        // Ten more sections under the platoon, each with a serving SL and ASL, and a grant inside it for every ASL.
        $units = app(UnitRepository::class);
        foreach (range(1, 10) as $n) {
            $section = $units->create(UnitType::Section, "Section {$n}", "{$n}/1/A/1-7", $this->platoon->id);
            ctHolder(200 + $n, "Leader.{$n}", "Section Leader {$n}/1/A/1-7", $section);
            $assistant = ctHolder(300 + $n, "Assistant.{$n}", "Assistant Section Leader {$n}/1/A/1-7", $section);
            PortalRoleGrant::factory()->create(['user_id' => $assistant->id, 'unit_id' => $section->id]);
        }

        expect(ctListed($this->pl))->toHaveCount(count($before) + 20)
            ->and($count($this->pl))->toBe($small);
    });
});
