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
     * Who may be a guest at `$sectionId`'s practice in the week opening at `$weekStart`: anyone who attended, whatever
     * their standing or placement (2026-11-15: HQ staff, a group such as ELOA or Reservists, unplaced, no longer
     * serving). Only accountability cares whether they are in the AO, so only the makeup depends on it ({@see guestKind()}).
     * Refused is only a trooper on the host section's own rollcall that week, who is marked there instead: their own
     * section ({@see TrooperRepository::ownSectionsInWeek()}) is where they are placed now, or for a week that has ended,
     * the section whose rollcall lists them that week, so a host cannot add its own trooper of that week who has moved
     * since. A departed trooper keeps their last placement but is on no rollcall, so this week they may be added.
     *
     * @throws GuestAttendanceRefusedException
     */
    private function refuseNonGuest(?Trooper $trooper, int $sectionId, CarbonImmutable $weekStart, UnitRepository $units, TrooperRepository $troopers): Trooper
    {
        if ($trooper === null) {
            throw GuestAttendanceRefusedException::unknownTrooper();
        }

        if ($this->onHostRollcall($trooper, $sectionId, $weekStart, $troopers)) {
            throw GuestAttendanceRefusedException::ownSection($trooper->display_name);
        }

        return $trooper;
    }

    /**
     * What the guest's entry means for accountability: `section` (their own section's rollcall takes the makeup), `staff`
     * (serving HQ staff of a platoon, company, battalion or the regiment, listed as a staff guest under their own HQ;
     * they have no rollcall, and their staff report stays theirs to send), or `none` (anyone else: a group such as ELOA
     * or Reservists, unplaced, or no longer serving; listed, with no makeup).
     *
     * @return 'section'|'staff'|'none'
     */
    private function guestKind(Trooper $trooper, CarbonImmutable $weekStart, UnitRepository $units, TrooperRepository $troopers): string
    {
        $own = $troopers->ownSectionsInWeek([$trooper->id], $weekStart)[$trooper->id] ?? null;
        $ended = WeekOf::hasEnded($weekStart, CarbonImmutable::now());

        if ($own !== null && $units->find($own)?->type === UnitType::Section && ($ended || $trooper->status->isServing())) {
            return 'section';
        }

        $placed = $trooper->primary_section_id === null ? null : $units->find($trooper->primary_section_id)?->type;

        return $trooper->status->isServing() && $placed !== null && $placed !== UnitType::Section && $placed !== UnitType::Group
            ? 'staff'
            : 'none';
    }

    /** Whether `$trooper` is on `$sectionId`'s own rollcall in the week opening at `$weekStart`. */
    private function onHostRollcall(Trooper $trooper, int $sectionId, CarbonImmutable $weekStart, TrooperRepository $troopers): bool
    {
        $own = $troopers->ownSectionsInWeek([$trooper->id], $weekStart)[$trooper->id] ?? null;

        // An ended week's own section is already the rollcall that listed them; this week's is where they are placed,
        // which lists them only while they serve.
        return $own === $sectionId && (WeekOf::hasEnded($weekStart, CarbonImmutable::now()) || $trooper->status->isServing());
    }
}
