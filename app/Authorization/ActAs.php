<?php

namespace App\Authorization;

use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UserRepository;
use Illuminate\Contracts\Session\Session;

/**
 * An Admin acting as another person (#379): unlike view-as (read-only, AD-9), every request runs with that person's
 * permissions, writes included. Kept in the server-side session, bound to the Admin who started it, so it can never
 * apply to anyone else's session.
 *
 * Checked again on every request ({@see target()}): only a real Admin may act, never as themselves or another Admin,
 * and only as someone the sign-in gate would let in right now ({@see ServingSession}). A stored choice that fails is
 * dropped, and the Admin is themselves again. That drop writes no `act_as.stopped` entry (this layer never writes the
 * audit log); the change that caused it, an Admin flag granted or revoked, or the roster sync, is recorded where it
 * happened.
 */
class ActAs
{
    private const KEY = 'act_as';

    /** The request attribute that holds the real Admin while they act as someone (set by the `ActAsUser` middleware). */
    public const ADMIN_ATTRIBUTE = 'acting_admin';

    public function __construct(
        private readonly Session $session,
        private readonly UserRepository $users,
        private readonly ServingSession $serving,
        private readonly TrooperRepository $troopers,
    ) {}

    /** How the red bar names the person: `SGT Vasquez.R`. */
    public function label(User $user): string
    {
        $rank = $user->cav_id !== null ? $this->troopers->ranksByCavId([$user->cav_id])->get($user->cav_id) : null;

        return trim("{$rank} {$user->username}");
    }

    /** Whether `$admin` may act as `$user` right now. */
    public function allows(User $admin, User $user): bool
    {
        return $admin->is_admin && ! $user->is_admin && $user->id !== $admin->id && ! $this->serving->mustEnd($user);
    }

    public function start(User $admin, User $user): void
    {
        $this->session->put(self::KEY, ['admin' => $admin->id, 'user' => $user->id]);
    }

    public function stop(): void
    {
        $this->session->forget(self::KEY);
    }

    /** The id of the person `$admin` stored, still checked or not; null when there is none. */
    public function storedFor(User $admin): ?int
    {
        $stored = $this->session->get(self::KEY);

        return is_array($stored) && ($stored['admin'] ?? null) === $admin->id && is_int($stored['user'] ?? null) ? $stored['user'] : null;
    }

    /**
     * The person `$admin` is acting as on this request, or null. A stored choice that no longer passes {@see allows()}
     * (the Admin lost the flag, the person became an Admin, left the Cav or was deleted) is dropped from the session.
     */
    public function target(User $admin): ?User
    {
        $id = $this->storedFor($admin);
        if ($id === null) {
            return null;
        }

        $user = $this->users->find($id);
        if ($user === null || ! $this->allows($admin, $user)) {
            $this->stop();

            return null;
        }

        return $user;
    }
}
