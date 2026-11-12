<?php

namespace App\Authorization;

use App\Models\ReportEditRequest;
use App\Models\User;

/**
 * Who may approve or deny a request to edit a locked report ({@see ReportEditRequest}): an Admin, or anyone who
 * commands a unit above the report's unit (a unit's weekly report), or above the staff member's HQ (a staff report),
 * by synced billet or as an acting lead. Never the person who asked, and never the staff member whose report it is.
 * Commanding the unit itself is not enough: a platoon's sergeant does not decide on their own platoon's report, and a
 * platoon leader does not read their sergeant's staff report ({@see StaffReportAccess}). Real authority, as every write
 * is: a view-as preview decides nothing.
 */
final class ReportEditAuthority
{
    public function __construct(private readonly ScopeResolver $scope) {}

    public function mayDecide(User $user, ReportEditRequest $request): bool
    {
        if ($user->id === $request->requested_by) {
            return false;
        }

        if ($request->trooper_id !== null && $request->trooper !== null && $this->scope->isOwnTrooper($user, $request->trooper)) {
            return false;
        }

        if ($this->scope->isAdmin($user)) {
            return true;
        }

        return $this->scope->commandingUnitsOver($user, $request->unit_id)
            ->contains(fn ($unit) => $unit->id !== $request->unit_id);
    }
}
