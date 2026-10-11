<?php

namespace App\Commands\Auth;

use App\Integration\Cav\Data\CavRosterProfile;
use App\Integration\Cav\Data\RosterType;
use App\Models\TrooperStatus;
use App\Models\User;
use App\Repositories\TrooperRepository;

/**
 * Decides whether a forum identity may start a portal session at all. Any 7cav.us forum account can
 * complete the OAuth handshake, and the portal shows every signed-in user the whole unit tree and
 * the staff grants list, so the handshake alone must not be enough: the user has to be an Admin
 * (AD-7, never locked out by roster data) or a serving trooper — standing on
 * `TrooperStatus::serving()`, the same allow-list command scope and staff grants read. A staff grant
 * alone admits nobody, since a grant lapses the moment its holder stops serving.
 *
 * Runs after {@see EnrichCavProfile}, which discovers the milpac link on a first login and drops one
 * Cav has proven wrong. Where the standing comes from depends on what that enrichment got:
 *
 *  - **Cav answered with the trooper's profile** — Cav's live roster decides, mapped onto
 *    `TrooperStatus` the way the roster sync maps it. It is fresher than the cache in both
 *    directions: a trooper who joined since the last sync gets in now, and one Cav already lists on
 *    Past Members (or any non-serving roster) is refused even while the cache still shows them
 *    serving.
 *  - **Cav could not answer** (an outage, a missing key, an unusable payload) — the local roster
 *    cache decides, never a further Cav call. That keeps Story 1.7's degraded-safe login: a stored
 *    link to a serving cached trooper still signs in. Someone with no link the cache knows cannot be
 *    confirmed either way and is told so, rather than told they are not a member; a cached
 *    departed trooper is refused, since the cache's own record is evidence.
 *  - **Cav answered that they hold no milpac** — enrichment has already dropped any stored link, so
 *    the cache path finds nothing and refuses them.
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
     * @param  CavRosterProfile|null  $live  the profile Cav returned during this very login, or null
     *                                       when enrichment produced none
     */
    public function handle(User $user, bool $rosterUnavailable, ?CavRosterProfile $live = null): Admission
    {
        if ($user->is_admin) {
            return Admission::Admitted;
        }

        if ($live !== null) {
            return self::statusFor($live->roster)->isServing() ? Admission::Admitted : Admission::NotServing;
        }

        $status = $user->cav_id === null ? null : $this->troopers->statusOf((int) $user->cav_id);

        if ($status?->isServing()) {
            return Admission::Admitted;
        }

        // A cached trooper on a non-serving standing is evidence in itself, Cav or no Cav. Only the
        // absence of any local record, with Cav unable to supply one, leaves the question open.
        return $status === null && $rosterUnavailable ? Admission::Unverifiable : Admission::NotServing;
    }

    /**
     * The standing a roster confers — the same mapping `Sync\SyncRosterHandler::statusFor()` writes
     * into the cache, so a live answer and a cached one are judged by one rule. Mirrored rather than
     * shared because Deptrac keeps Commands and Sync from reaching each other, and neither the Model
     * nor the Integration layer may name the other's enum. An unrecognised roster (null) is what the
     * sync treats as departed, so it admits no one here either.
     */
    private static function statusFor(?RosterType $roster): TrooperStatus
    {
        return match ($roster) {
            RosterType::Combat => TrooperStatus::Combat,
            RosterType::Reserve => TrooperStatus::Reserve,
            RosterType::Eloa => TrooperStatus::Eloa,
            default => TrooperStatus::Inactive,
        };
    }
}
