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
use Illuminate\Support\Collection;

/**
 * Who files a weekly staff report, and who may read one.
 *
 * Who files: a serving trooper placed on a platoon, company or battalion HQ node when the Cav week ended (the PL, PSG,
 * CO, XO, 1SG, the battalion command team and anyone else placed there, ACD included), by the placement history, so a
 * past week asks who was on the HQ then. Regiment HQ, staff departments, ELOA, Reservists and New Recruits do not file
 * one.
 *
 * Who sends it: the staff member themselves, signed in as themselves ({@see ScopeResolver::ownCavId()}): never someone
 * acting as them, never in a view-as preview, and nobody on their behalf, Admins included.
 *
 * Who reads it: the staff member; whoever reads the unit above their HQ (its command and the chain above, plus a staff
 * grant, an overseer or the Regimental HQ read there, as unit reports are read); and Admins. For a battalion HQ that is
 * whoever's scope holds the regiment: regiment command and Admins. A peer on the same HQ (a PL and their PSG) and
 * anyone below it do not.
 */
class StaffReportAccess
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly TrooperPlacementRepository $placements,
    ) {}

    /** Whether a trooper placed on `$unit` files a staff report: a platoon, company or battalion HQ node (ACD too). */
    public static function filesFrom(Unit $unit): bool
    {
        return $unit->type === UnitType::Platoon || $unit->type === UnitType::Company || $unit->type === UnitType::Battalion;
    }

    /** The platoon, company or battalion HQ the trooper was placed on when the week ended, or null when they file none for it. */
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

    /** Whether the user is placed on a platoon, company or battalion HQ now, for the sidebar's "Staff report". */
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

    /**
     * The platoon, company and battalion HQs whose staff's reports `$user` reads, down the tree: every one for an Admin, else
     * each whose parent their scope holds (as {@see mayRead()} reads it). Empty for a PL or PSG, whose scope holds no
     * HQ's parent: they read only their own.
     *
     * @return Collection<int, Unit>
     */
    public function readableHqs(User $user): Collection
    {
        $admin = $this->scope->actsAsAdmin($user);
        $scope = $admin ? collect() : $this->scope->resolve($user)->map(fn ($id) => (int) $id)->flip();

        return $this->units->tree()
            ->filter(fn (Unit $unit) => self::filesFrom($unit)
                && ($admin || ($unit->parent_id !== null && $scope->has((int) $unit->parent_id))))
            ->values();
    }

    /**
     * What the sidebar's staff report item opens for `$user`: `overview` ("Staff reports") for anyone who reads some HQ
     * staff's reports, `own` ("Staff report") for an HQ staff member who reads only their own, else null.
     */
    public function navFor(User $user): ?string
    {
        return match (true) {
            $this->readableHqs($user)->isNotEmpty() => 'overview',
            $this->isHqStaffNow($user) => 'own',
            default => null,
        };
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
