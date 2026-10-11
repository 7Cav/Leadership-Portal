<?php

namespace App\Commands\Auth;

use App\Models\User;
use App\Repositories\TrooperRepository;

/**
 * Decides whether a forum identity may start a portal session at all. Any 7cav.us forum account can
 * complete the OAuth handshake, and the portal shows every signed-in user the whole unit tree and
 * the staff grants list, so the handshake alone must not be enough: the user has to be an Admin
 * (AD-7, never locked out by roster data) or linked to a serving trooper — `users.cav_id` → a
 * cached `troopers` row whose standing is on `TrooperStatus::serving()`, the same allow-list
 * command scope and staff grants read. A staff grant alone admits nobody, since a grant lapses the
 * moment its holder stops serving.
 *
 * Runs after {@see EnrichCavProfile}, which is what discovers the milpac link on a first login and
 * drops one Cav has proven wrong. The check itself reads only the local roster cache, never Cav:
 * that is what keeps Story 1.7's degraded-safe login — a trooper whose stored link already points
 * at a serving cached trooper still signs in while Cav is down. Someone with no link the cache
 * knows cannot be confirmed while Cav is down either, and is told that rather than told they are
 * not a member.
 *
 * The cache trails Cav by up to one roster sync, so a trooper who joined since the last sync is
 * refused until it runs, and one who left keeps signing in until it does.
 */
class AdmitServingMember
{
    public function __construct(
        private readonly TrooperRepository $troopers,
    ) {}

    /**
     * @param  bool  $rosterUnavailable  whether enrichment failed for a reason that says nothing
     *                                   about the user (an outage, a missing key, an unusable
     *                                   payload) — as opposed to Cav answering that they hold no milpac
     */
    public function handle(User $user, bool $rosterUnavailable): Admission
    {
        if ($user->is_admin) {
            return Admission::Admitted;
        }

        $status = $user->cav_id === null ? null : $this->troopers->statusOf((int) $user->cav_id);

        if ($status?->isServing()) {
            return Admission::Admitted;
        }

        // A cached trooper on a non-serving standing is evidence in itself, Cav or no Cav. Only the
        // absence of any local record, with Cav unable to supply one, leaves the question open.
        return $status === null && $rosterUnavailable ? Admission::Unverifiable : Admission::NotServing;
    }
}
