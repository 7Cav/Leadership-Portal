<?php

namespace App\Commands\Guests;

use App\Authorization\ScopeResolver;
use App\Models\Trooper;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;

/**
 * The host-side gates both guest Commands share, mirroring the rollcall's own write (`MarkAttendanceHandler::handle()`):
 * the host section must be in the actor's write scope (AD-8; a staff grant and a view-as preview of a narrower scope
 * only read), must be a section, and the week must not be before `WeekOf::floor()`. The sent-report and confirmation
 * locks are checked by each handler inside its audited transaction, under the locks.
 */
trait GuardsHostPractice
{
    /**
     * @throws GuestAttendanceForbiddenException
     * @throws GuestAttendanceRefusedException
     */
    private function hostWeek(ScopeResolver $scope, UnitRepository $units, User $actor, int $sectionId, CarbonImmutable $week): CarbonImmutable
    {
        // Scope first, so a section outside it is a 403 whether or not it exists (AD-8).
        if (! $scope->writeScope($actor)->contains($sectionId)) {
            throw new GuestAttendanceForbiddenException($sectionId);
        }

        if ($units->find($sectionId)?->type !== UnitType::Section) {
            throw GuestAttendanceRefusedException::notASection($sectionId);
        }

        $weekStart = WeekOf::start($week);

        if (WeekOf::isBeforeFloor($weekStart)) {
            throw GuestAttendanceRefusedException::weekBeforeFloor($weekStart);
        }

        return $weekStart;
    }

    /**
     * Who may be a guest at `$sectionId`'s practice in the week opening at `$weekStart`: a serving trooper with a section
     * of their own that week (never HQ staff or a group), which is not the host section (a host trooper is marked on the
     * rollcall itself). Their own section is the one their makeup goes to ({@see TrooperRepository::ownSectionsInWeek()}):
     * where they are placed now, or for a week that has ended, the section whose rollcall lists them that week, so a
     * host cannot add its own trooper of that week who has moved since. A departed trooper keeps their last placement,
     * so standing is checked, not only placement.
     *
     * @throws GuestAttendanceRefusedException
     */
    private function refuseNonGuest(?Trooper $trooper, int $sectionId, CarbonImmutable $weekStart, UnitRepository $units, TrooperRepository $troopers): Trooper
    {
        if ($trooper === null) {
            throw GuestAttendanceRefusedException::unknownTrooper();
        }

        if (! $trooper->status->isServing()) {
            throw GuestAttendanceRefusedException::notServing($trooper->display_name);
        }

        $own = $troopers->ownSectionsInWeek([$trooper->id], $weekStart)[$trooper->id] ?? null;

        return match (true) {
            // HQ staff and groups (2026-10-24) have no rollcall of their own to carry the makeup, so they are not guests.
            $own === null,
            $units->find($own)?->type !== UnitType::Section => throw GuestAttendanceRefusedException::notPlaced($trooper->display_name),
            $own === $sectionId => throw GuestAttendanceRefusedException::ownSection($trooper->display_name),
            default => $trooper,
        };
    }
}
