<?php

namespace App\Authorization;

use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TrooperPlacementRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonInterface;

/**
 * Who files a weekly staff report, and who may read one (policy, 2026-10-29).
 *
 * Who files: a serving trooper placed on a platoon or company HQ node when the Cav week ended (the PL, PSG, CO, XO, 1SG
 * and anyone else placed there), by the placement history, so a past week asks who was on the HQ then. Battalion and
 * regiment HQ, staff departments, ELOA, Reservists and New Recruits do not file one.
 *
 * Who sends it: the staff member themselves, signed in as themselves ({@see ScopeResolver::ownCavId()}): never someone
 * acting as them, never in a view-as preview, and nobody on their behalf, Admins included.
 *
 * Who reads it: the staff member; whoever reads the unit above their HQ (its command and the chain above, plus a staff
 * grant, an overseer or the Regimental HQ read there, as unit reports are read); and Admins. A peer on the same HQ
 * (a PL and their PSG) and anyone below it do not.
 */
class StaffReportAccess
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly TrooperPlacementRepository $placements,
    ) {}

    /** Whether a trooper placed on `$unit` files a staff report: a platoon or company HQ node. */
    public static function filesFrom(Unit $unit): bool
    {
        return $unit->type === UnitType::Platoon || $unit->type === UnitType::Company;
    }

    /** The platoon or company HQ the trooper was placed on when the week ended, or null when they file none for it. */
    public function hqInWeek(Trooper $trooper, CarbonInterface $weekStart): ?Unit
    {
        $unitId = $this->placements->unitOfTrooperAtWeekEnd($trooper, $weekStart);
        $unit = $unitId === null ? null : $this->units->find($unitId);

        return $unit !== null && self::filesFrom($unit) ? $unit : null;
    }

    /** The HQ the trooper files a staff report from for the week: {@see hqInWeek()} while they still serve. */
    public function owes(Trooper $trooper, CarbonInterface $weekStart): ?Unit
    {
        return $trooper->status->isServing() ? $this->hqInWeek($trooper, $weekStart) : null;
    }

    /** The user's own trooper, while signed in as themselves (not acting as anyone, not viewing as anything). */
    public function ownTrooper(User $user): ?Trooper
    {
        $cavId = $this->scope->ownCavId($user);

        return $cavId === null ? null : $this->troopers->byCavId([$cavId])->first();
    }

    /** Whether the user is placed on a platoon or company HQ now, for the sidebar's "Staff report". */
    public function isHqStaffNow(User $user): bool
    {
        $trooper = $this->ownTrooper($user);
        $unit = $trooper?->primary_section_id === null ? null : $this->units->find($trooper->primary_section_id);

        return $unit !== null && $trooper->status->isServing() && self::filesFrom($unit);
    }

    /** Whether `$user` may send `$trooper`'s staff report: only the trooper, signed in as themselves. */
    public function maySend(User $user, Trooper $trooper): bool
    {
        return $this->scope->isOwnTrooper($user, $trooper);
    }

    /** Whether `$user` may read `$trooper`'s staff report from `$hq`: themselves, the chain above the HQ, Admins. */
    public function mayRead(User $user, Trooper $trooper, ?Unit $hq): bool
    {
        if ($this->scope->isOwnTrooper($user, $trooper) || $this->scope->actsAsAdmin($user)) {
            return true;
        }

        return $hq?->parent_id !== null && $this->scope->resolve($user)->contains($hq->parent_id);
    }
}
