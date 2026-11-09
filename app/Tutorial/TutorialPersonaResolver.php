<?php

namespace App\Tutorial;

use App\Authorization\ScopeResolver;
use App\Authorization\StaffReportAccess;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;

/** Reads the person's real standing, once, when they start the tutorial. Read-only, on the real database. */
final class TutorialPersonaResolver
{
    /** Highest first: the echelon a leader of several units plays. A regiment or portfolio command plays a battalion. */
    private const ECHELONS = [UnitType::Regiment, UnitType::Battalion, UnitType::Company, UnitType::Platoon, UnitType::Section];

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly StaffReportAccess $staff,
        private readonly TrooperRepository $troopers,
        private readonly UnitRepository $units,
    ) {}

    public function for(User $user): TutorialPersona
    {
        $types = $this->scope->commandedBy($user)->map(fn (Unit $unit) => $unit->type);
        $leads = null;
        foreach (self::ECHELONS as $type) {
            if ($types->contains($type)) {
                $leads = $type === UnitType::Regiment ? UnitType::Battalion : $type;
                break;
            }
        }

        $trooper = $user->cav_id === null ? null : $this->troopers->byCavId([(int) $user->cav_id])->first();
        $hq = null;
        if ($this->staff->isHqStaffNow($user) && $trooper?->primary_section_id !== null) {
            $hq = $this->units->find((int) $trooper->primary_section_id)?->type;
        }

        return new TutorialPersona(
            admin: $this->scope->isAdmin($user),
            leads: $leads,
            hqStaff: $hq,
            username: (string) $user->username,
            forumUserId: (int) $user->forum_user_id,
            cavId: $user->cav_id === null ? null : (int) $user->cav_id,
            rankShort: (string) ($trooper->rank_short ?? 'SGT'),
            rank: (string) ($trooper->rank ?? 'Sergeant'),
        );
    }
}
