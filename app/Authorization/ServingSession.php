<?php

namespace App\Authorization;

use App\Models\User;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperRepository;
use Illuminate\Contracts\Session\Session;

/**
 * Keeps the sign-in gate (`Commands\Auth\AdmitServingMember`) true for the length of a session: the
 * `EnsureServingMember` middleware asks {@see mustEnd()} on every authenticated request. The rule is the
 * sign-in rule, read against what the portal knows now: an Admin (AD-7, never locked out by roster data) or
 * a user linked to a trooper who is not known to have left.
 *
 * Sign-in may admit on Cav's live roster, which can be ahead of the cache. A trooper who re-enlisted since
 * the last roster sync is serving on Cav but still departed in the cache; ending their session on the cache
 * would send them round a sign-in loop until the next sync. So a live admission is remembered in the
 * session ({@see admittedLive()}), bound to the user, and outranks a departed cache record until a roster
 * sync that started after it has completed cleanly. From then on the cache has caught up with what Cav
 * said at sign-in, and its answer stands again.
 */
class ServingSession
{
    private const KEY = 'admitted_live';

    /** The sync_runs `source` the roster sync records under. */
    private const ROSTER_SOURCE = 'roster';

    public function __construct(
        private readonly Session $session,
        private readonly TrooperRepository $troopers,
        private readonly SyncRunRepository $syncRuns,
    ) {}

    /**
     * Note that this session was admitted on Cav's live roster just now. Called right after the session
     * starts, so the session regeneration at sign-in cannot carry an older note across.
     */
    public function admittedLive(User $user): void
    {
        $this->session->put(self::KEY, ['user' => $user->id, 'at' => now()->getTimestamp()]);
    }

    /**
     * Whether the user's session has to end now:
     *
     *  - an **Admin** never;
     *  - a non-Admin with **no milpac link** always: sign-in admits nobody without one, so this is an Admin
     *    whose flag was revoked, or a user whose link moved to another account;
     *  - a linked user whose trooper the cache **does not hold** is let through: sign-in may have admitted
     *    them live ahead of the next sync, and the absence of a record is no evidence against them;
     *  - a linked user whose cached trooper is **departed**, unless this session was admitted live and no
     *    clean roster sync has started since (see the class docblock).
     */
    public function mustEnd(User $user): bool
    {
        if ($user->is_admin) {
            return false;
        }

        if ($user->cav_id === null) {
            return true;
        }

        $status = $this->troopers->statusOf((int) $user->cav_id);

        if ($status === null || $status->isServing()) {
            return false;
        }

        return ! $this->liveAdmissionStands($user);
    }

    private function liveAdmissionStands(User $user): bool
    {
        $stored = $this->session->get(self::KEY);

        if (! is_array($stored) || ($stored['user'] ?? null) !== $user->id || ! is_int($stored['at'] ?? null)) {
            return false;
        }

        // `started_at` is second-precision; a run that started in the admission's second counts as after
        // it, so the doubt goes to the cache.
        $sync = $this->syncRuns->latestSuccessful(self::ROSTER_SOURCE);

        return $sync === null || $sync->started_at->getTimestamp() < $stored['at'];
    }
}
