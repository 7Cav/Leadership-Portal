<?php

namespace App\Authorization;

use App\Models\SyncRun;
use App\Models\Trooper;
use App\Models\User;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperRepository;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;

/**
 * Keeps the sign-in gate (`Commands\Auth\AdmitServingMember`) true for the length of a session: the
 * `EnsureServingMember` middleware asks {@see mustEnd()} on every authenticated request. The rule is the
 * sign-in rule, read against what the portal knows now: an Admin (AD-7, never locked out by roster standing) or
 * a user linked to a trooper who is not known to have left. A cached trooper whose forum identity no longer
 * matches the account ends either kind of session: the old link must not grant the new holder's identity.
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
     *  - an **Admin** by the flag never over roster standing. An Admin by billet (`ScopeResolver::isAdmin()`)
     *    is one only while serving, so they are answered like any member below, and a departed one is signed out;
     *  - a non-Admin with **no milpac link** always: sign-in admits nobody without one, so this is an Admin
     *    whose flag was revoked, or a user whose link moved to another account;
     *  - a linked user whose trooper the cache **does not hold** is let through: sign-in may have admitted
     *    them live ahead of the next sync, and the absence of a record is no evidence against them;
     *  - a linked user, including a flag Admin, whose cached trooper now has a **different forum identity**
     *    is signed out: a reassigned milpac id must not confer the new holder's identity or billet;
     *  - a linked user whose cached trooper is **departed**, unless this session was admitted live and no
     *    clean roster sync has started since (see the class docblock).
     */
    public function mustEnd(User $user): bool
    {
        if ($user->cav_id === null) {
            return ! $user->is_admin;
        }

        return $this->endsWith($user, $this->troopers->byCavId([(int) $user->cav_id])->get((int) $user->cav_id));
    }

    /**
     * {@see mustEnd()} for many users at once: the ids of those it would answer true for, with their standings read in
     * one query (#496), for a list that must leave out anyone who could not sign in (the act as picker).
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, int> user ids
     */
    public function mustEndAmong(Collection $users): Collection
    {
        $cavIds = $users
            ->filter(fn (User $user) => $user->cav_id !== null)
            ->map(fn (User $user) => (int) $user->cav_id)
            ->unique()
            ->values()
            ->all();
        $troopers = $this->troopers->byCavId($cavIds);

        return $users
            ->filter(fn (User $user) => $user->cav_id === null
                ? ! $user->is_admin
                : $this->endsWith($user, $troopers->get((int) $user->cav_id)))
            ->map(fn (User $user) => (int) $user->id)
            ->values();
    }

    /** {@see mustEnd()} for a linked user, given their cached trooper (null for none). */
    private function endsWith(User $user, ?Trooper $trooper): bool
    {
        if ($trooper === null) {
            return false;
        }

        // Cav can reassign a milpac id. A roster sync may then replace the cached trooper's
        // forum identity and billet while an earlier holder still has this id in their session.
        // Do not let that earlier holder inherit the new trooper's scope. A live admission
        // cannot override a mismatched cached identity: the cache would otherwise confer the
        // other person's billet during the interval before the next sync.
        if ($trooper->forum_user_id === null || (int) $trooper->forum_user_id !== (int) $user->forum_user_id) {
            return true;
        }

        if ($user->is_admin) {
            return false;
        }

        if ($trooper->status === null || $trooper->status->isServing()) {
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
        // it, so the doubt goes to the cache. Only a whole-roster run counts: an echelon sync refreshes one unit.
        $sync = $this->syncRuns->latestSuccessful(SyncRun::SOURCE_ROSTER);

        return $sync === null || $sync->started_at->getTimestamp() < $stored['at'];
    }
}
