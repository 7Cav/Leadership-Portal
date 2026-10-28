<?php

namespace App\Authorization;

use App\Models\Unit;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Repositories\UserRepository;
use Illuminate\Contracts\Session\Session;

/**
 * An Admin acting as someone else (#379): unlike view-as (read-only, AD-9), every request runs with their permissions,
 * writes included. Kept in the server-side session, bound to the Admin who started it, so it can never apply to anyone
 * else's session. Two kinds, stored as a {@see ViewAsTarget} (a kind and an id):
 *
 *  - a **person**: the request runs as that user. Only a real Admin may, never as themselves, and only as someone the
 *    sign-in gate would let in right now ({@see ServingSession}). Another Admin is acted as without their Admin
 *    rights: their billet, acting leads and grants only ({@see withoutAdmin()}).
 *  - a **billet**: a unit's leader, with no person behind it, so it works for a vacant billet too. The request runs as
 *    a stand-in for the Admin ({@see standIn()}) whose only command is that unit ({@see ActingBillet}).
 *
 * Checked again on every request ({@see target()}). A stored choice that fails is dropped, and the Admin is
 * themselves again. That drop writes no `act_as.stopped` entry (this layer never writes the audit log); the change
 * that caused it, an Admin flag granted or revoked, the roster sync, a unit deleted, is recorded where it happened.
 */
class ActAs
{
    private const KEY = 'act_as';

    /** The request attribute that holds the real Admin while they act as someone (set by the `ActAsUser` middleware). */
    public const ADMIN_ATTRIBUTE = 'acting_admin';

    /** The request attribute that holds what they act as, an {@see Acting} (set by the same middleware). */
    public const ACTING_ATTRIBUTE = 'acting_as';

    public function __construct(
        private readonly Session $session,
        private readonly UserRepository $users,
        private readonly UnitRepository $units,
        private readonly ServingSession $serving,
        private readonly TrooperRepository $troopers,
    ) {}

    /** How the red bar names a person: `SGT Vasquez.R`. */
    public function label(User $user): string
    {
        $rank = $user->cav_id !== null ? $this->troopers->ranksByCavId([$user->cav_id])->get($user->cav_id) : null;

        return trim("{$rank} {$user->username}");
    }

    /**
     * Whether `$admin` may act as the person `$user` right now: anyone but themselves who could sign in. An Admin can be
     * acted as too, but never as an Admin ({@see withoutAdmin()}): only their billet, acting leads and grants count.
     */
    public function allows(User $admin, User $user): bool
    {
        return $admin->is_admin && $user->id !== $admin->id && ! $this->serving->mustEnd($user);
    }

    /** Whether `$admin` may act as the leader of `$unit`: any real Admin, for any unit, vacant or held. */
    public function allowsBillet(User $admin): bool
    {
        return $admin->is_admin;
    }

    public function start(User $admin, ViewAsTarget $target): void
    {
        $this->session->put(self::KEY, ['admin' => $admin->id, 'kind' => $target->kind->value, 'id' => $target->id]);
    }

    public function stop(): void
    {
        $this->session->forget(self::KEY);
    }

    /** What `$admin` stored, still checked or not; null when there is none or it is not one this class wrote. */
    public function storedFor(User $admin): ?ViewAsTarget
    {
        $stored = $this->session->get(self::KEY);
        if (! is_array($stored) || ($stored['admin'] ?? null) !== $admin->id || ! is_int($stored['id'] ?? null)) {
            return null;
        }

        $kind = ViewAsKind::tryFrom((string) ($stored['kind'] ?? ''));

        return $kind ? new ViewAsTarget($kind, $stored['id']) : null;
    }

    /**
     * Who `$admin` is acting as on this request, or null. A stored choice that no longer holds (the Admin lost the
     * flag; the person became an Admin, left the Cav or was deleted; the unit was deleted) is dropped from the session.
     */
    public function target(User $admin): ?Acting
    {
        $stored = $this->storedFor($admin);
        $acting = match ($stored?->kind) {
            ViewAsKind::User => $this->asPerson($admin, $stored->id),
            ViewAsKind::Unit => $this->asBillet($admin, $stored->id),
            // Never stored: acting as a trooper with no account is stored as their billet.
            ViewAsKind::Trooper => null,
            null => null,
        };

        if ($stored !== null && $acting === null) {
            $this->stop();
        }

        return $acting;
    }

    /**
     * Who the request runs as while the Admin holds a billet: the Admin's own account with the Admin flag off and no
     * milpac link, so none of their own billets, acting leads or grants count, only the billet ({@see ActingBillet}).
     * Its attributes are its original ones, so nothing is dirty: a save, should anything ever try one, writes nothing.
     */
    public function standIn(User $admin): User
    {
        $standIn = clone $admin;
        $standIn->setRawAttributes(['is_admin' => false, 'cav_id' => null] + $admin->getAttributes(), sync: true);

        return $standIn;
    }

    /**
     * The person as acted as: unchanged, or for an Admin, their account with the Admin flag off, so what they command by
     * billet, acting lead or grant counts and nothing an Admin may do. Never dirty, like {@see standIn()}.
     */
    public function withoutAdmin(User $user): User
    {
        if (! $user->is_admin) {
            return $user;
        }

        $plain = clone $user;
        $plain->setRawAttributes(['is_admin' => false] + $user->getAttributes(), sync: true);

        return $plain;
    }

    private function asPerson(User $admin, int $id): ?Acting
    {
        $user = $this->users->find($id);

        return $user !== null && $this->allows($admin, $user) ? new Acting($this->withoutAdmin($user), $user, null, $this->label($user)) : null;
    }

    private function asBillet(User $admin, int $id): ?Acting
    {
        $unit = $this->units->find($id);

        return $unit !== null && $this->allowsBillet($admin) ? new Acting($this->standIn($admin), null, $unit, $unit->billet()) : null;
    }
}
