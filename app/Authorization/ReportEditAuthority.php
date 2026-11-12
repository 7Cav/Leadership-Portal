<?php

namespace App\Authorization;

use App\Models\ReportEditRequest;
use App\Models\User;

/**
 * Who may approve or deny a request to edit a locked report ({@see ReportEditRequest}): an Admin, or anyone who
 * commands a unit above the report's unit (a unit's weekly report), or above the staff member's HQ (a staff report),
 * by synced billet or as an acting lead. Never the person who asked, and never the staff member whose report it is,
 * whoever either of them is acting as: the request keeps the signed-in person behind an act as, and the decider's is
 * checked too. Commanding the unit itself is not enough, Admins included: a platoon's sergeant does not decide on their
 * own platoon's report, and a platoon leader does not read their sergeant's staff report ({@see StaffReportAccess}).
 * Real authority, as every write is: a view-as preview decides nothing.
 */
final class ReportEditAuthority
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly RealActor $real,
    ) {}

    public function mayDecide(User $user, ReportEditRequest $request): bool
    {
        // Everyone this request runs as: the user, and while acting as someone the signed-in person behind them.
        $people = array_filter([$user, $this->real->get()]);
        $askers = array_filter([$request->requested_by, $request->real_requested_by]);

        foreach ($people as $person) {
            if (in_array($person->id, $askers, true)) {
                return false;
            }

            // The staff member, by their own milpac, whoever they are acting as.
            if ($request->trooper !== null && $person->cav_id !== null && (int) $person->cav_id === (int) $request->trooper->cav_id) {
                return false;
            }
        }

        $commands = $this->scope->commandingUnitsOver($user, $request->unit_id);
        $above = $commands->contains(fn ($unit) => $unit->id !== $request->unit_id);

        if ($this->scope->isAdmin($user)) {
            // An Admin on the unit's own command team decides from above it, like anyone else on that team.
            return $above || ! $commands->contains(fn ($unit) => $unit->id === $request->unit_id);
        }

        return $above;
    }
}
