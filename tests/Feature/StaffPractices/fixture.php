<?php

use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitKind;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * The staff practice tests' shared tree (#448), with the clock on Thursday 08OCT26, in the Cav week of 03OCT26:
 *
 *   7CAV ─┬─ 1-7 ─ A/1-7 (CO `co`) ─ 1/A/1-7 (PL `pl`, PSG Brandt.L) ─┬─ 1/1/A/1-7 (SL `sl`, rifleman Okafor.B)
 *         │                                                         └─ 2/1/A/1-7
 *         ├─ Development Command (staff) ─ S6 (staff, lead `s6Lead`, member Vance.K who holds a read grant on S6)
 *         └─ ELOA, Reservists, New Recruits (groups, not staff), one trooper in each
 *
 * plus an Admin, and a staff grant on the company (`grant`). Brandt.L and Vance.K each have a portal account.
 *
 * @return array{units: array<string, Unit>, users: array<string, User>, troopers: array<string, Trooper>}
 */
function staffPracticeTree(): array
{
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC'));

    $units = app(UnitRepository::class);
    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $host = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);
    $other = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $platoon->id);
    $devcom = $units->create(UnitType::Group, 'Development Command', null, $regiment->id, UnitKind::Staff);
    $s6 = $units->create(UnitType::Group, 'S6', null, $devcom->id, UnitKind::Staff);
    $eloa = $units->create(UnitType::Group, 'ELOA', null, $regiment->id, UnitKind::Eloa);
    $reserve = $units->create(UnitType::Group, 'Reservists', null, $regiment->id, UnitKind::Reserve);
    $recruit = $units->create(UnitType::Group, 'New Recruits', null, $regiment->id, UnitKind::Recruit);

    $psg = Trooper::factory()->create(['primary_section_id' => $platoon->id, 'username' => 'Brandt.L']);
    $s6Member = Trooper::factory()->create(['primary_section_id' => $s6->id, 'username' => 'Vance.K']);

    $users = [
        'admin' => User::factory()->admin()->create(['username' => 'Hale.S']),
        'pl' => User::factory()->commanding($platoon, ['primary_section_id' => $platoon->id, 'username' => 'Mercer.J'])->create(['username' => 'Mercer.J']),
        'co' => User::factory()->commanding($company, ['primary_section_id' => $company->id])->create(),
        'sl' => User::factory()->commanding($host, ['primary_section_id' => $host->id])->create(),
        's6Lead' => User::factory()->commanding($s6, ['primary_section_id' => $s6->id, 'username' => 'Ruiz.T'])->create(['username' => 'Ruiz.T']),
        'grant' => User::factory()->serving()->create(),
        'psg' => User::factory()->create(['cav_id' => $psg->cav_id, 'username' => 'Brandt.L']),
        's6Member' => User::factory()->create(['cav_id' => $s6Member->cav_id, 'username' => 'Vance.K']),
    ];
    PortalRoleGrant::factory()->create(['user_id' => $users['grant']->id, 'unit_id' => $company->id]);
    PortalRoleGrant::factory()->create(['user_id' => $users['s6Member']->id, 'unit_id' => $s6->id]);

    return [
        'units' => compact('regiment', 'battalion', 'company', 'platoon', 'host', 'other', 'devcom', 's6', 'eloa', 'reserve', 'recruit'),
        'users' => $users,
        'troopers' => [
            'psg' => $psg,
            's6Member' => $s6Member,
            'rifleman' => Trooper::factory()->create(['primary_section_id' => $host->id, 'username' => 'Okafor.B']),
            'eloa' => Trooper::factory()->create(['primary_section_id' => $eloa->id]),
            'reservist' => Trooper::factory()->create(['primary_section_id' => $reserve->id]),
            'recruit' => Trooper::factory()->create(['primary_section_id' => $recruit->id]),
        ],
    ];
}

/** The Cav week the clock sits in. */
function staffPracticeWeek(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');
}
