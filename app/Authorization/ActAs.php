<?php

namespace App\Authorization;

use App\Models\Unit;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitRepository;
use App\Repositories\UserRepository;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;

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
 * A **leader** (#432), someone who is not an Admin, may act as a person too, but only as a serving billet holder
 * strictly below them inside their own write scope ({@see leadsBelow()}): never themselves, a peer, anyone above, an
 * Admin, nor anyone whose own authority (a grant, an acting lead) reaches outside that scope, nor anyone whose command
 * covers the leader's own placement (their own Section Leader, when they are acting lead of the platoon above it). Their
 * write scope is the roster-derived command scope, so a staff grant (read-only) confers nothing. A leader never acts
 * as a billet, and never chains: while acting they stop before they act as another.
 *
 * An Admin who already acts as someone ("via") is held to that identity's reach when they start another: acting as a
 * leader, they may start only what the leader could, and the pair is checked together on every request, so what they
 * reach through a leader can never exceed that leader's scope. They stop to be an Admin again. The session key's
 * `admin` is the real signed-in user, who for a leader is not an Admin.
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
        private readonly ScopeResolver $scope,
        private readonly UnitCommandMappingRepository $commands,
    ) {}

    /** How the red bar names a person: `SGT Vasquez.R`. */
    public function label(User $user): string
    {
        $rank = $user->cav_id !== null ? $this->troopers->ranksByCavId([$user->cav_id])->get($user->cav_id) : null;

        return trim("{$rank} {$user->username}");
    }

    /**
     * Whether `$actor` may act as the person `$user` right now, `$via` being what they already act as, if anything. An
     * Admin acting as nobody: anyone but themselves who could sign in (an Admin too, but never with their Admin rights,
     * {@see withoutAdmin()}). Anyone else, a leader, or an Admin acting as one: only a serving billet holder strictly
     * below what that identity commands ({@see leadsBelow()}).
     */
    public function allows(User $actor, User $user, ?ViewAsTarget $via = null): bool
    {
        if ($user->id === $actor->id || $this->serving->mustEnd($user)) {
            return false;
        }

        $commanded = $this->commandOf($actor, $via);

        return $commanded === null || $this->leadsBelow($commanded, $user, $this->placementOf($this->measuredFrom($actor, $via)));
    }

    /** Whether `$actor` may act as the leader of a unit: a real Admin acting as nobody, for any unit, vacant or held. */
    public function allowsBillet(User $actor, ?ViewAsTarget $via = null): bool
    {
        return $this->commandOf($actor, $via) === null;
    }

    /**
     * Whether `$actor`, signed in as themselves, holds the authority to act as anyone: an Admin, or someone who commands
     * a unit with units below it. A staff grant never does. The gate before {@see allows()}, which says who exactly.
     */
    public function leads(User $actor): bool
    {
        return $actor->is_admin || $this->scope->strictlyBelow($this->scope->commandedBy($actor))->isNotEmpty();
    }

    /**
     * Whether a picker for acting as someone should be offered to `$actor` now: a leader, or an Admin acting as one, who
     * has units below them to start from. Never to an Admin acting as nobody (view-as leads them to the person) nor to
     * someone acting as a person who is not an Admin (no chains).
     */
    public function offers(User $actor): bool
    {
        $commanded = $this->startingPoint($actor);

        return $commanded !== null && $this->scope->strictlyBelow($commanded)->isNotEmpty();
    }

    /**
     * Who `$actor` may act as right now: serving billet holders strictly below what they command, as the picker lists
     * them, in username order. Empty when {@see offers()} is false.
     *
     * @return Collection<int, User>
     */
    public function candidates(User $actor): Collection
    {
        $commanded = $this->startingPoint($actor);
        $below = $commanded === null ? collect() : $this->scope->strictlyBelow($commanded);
        if ($below->isEmpty()) {
            return collect();
        }

        $others = $this->users->all()->reject(fn (User $user) => $user->id === $actor->id || $user->is_admin || $user->cav_id === null);
        $held = $this->commands->commandedUnitsByCavId($others->pluck('cav_id')->all());
        $placement = $this->placementOf($this->measuredFrom($actor, $this->viaFor($actor)));

        // The units below are worked out once; each person is then only checked for what is theirs: standing and reach.
        return $others
            ->filter(fn (User $user) => $held->has($user->cav_id)
                && $below->contains($held->get($user->cav_id)->id)
                && ! $this->serving->mustEnd($user)
                && $this->withinAndNotOver($this->scope->realScope($user), $below, $placement))
            ->values();
    }

    /**
     * What `$actor` acts as right now and may start another from, or null when they act as nobody: the stored choice
     * once it is checked ({@see target()}), which drops one that no longer holds.
     */
    public function viaFor(User $actor): ?ViewAsTarget
    {
        return $this->target($actor) !== null ? $this->storedFor($actor) : null;
    }

    /** Whether `$actor` acts through a choice that is itself made through another ({@see start()}): the chain ends here. */
    public function nested(User $actor): bool
    {
        return $this->storedVia($actor) !== null;
    }

    /**
     * Store the choice. `$via` is what `$actor` acted as when they made it, kept so the pair is checked together on
     * every request: only an Admin has one, as a leader's own act-as ends before another starts.
     */
    public function start(User $actor, ViewAsTarget $target, ?ViewAsTarget $via = null): void
    {
        $this->session->put(self::KEY, [
            'admin' => $actor->id,
            'kind' => $target->kind->value,
            'id' => $target->id,
            'via' => $via === null ? null : ['kind' => $via->kind->value, 'id' => $via->id],
        ]);
    }

    public function stop(): void
    {
        $this->session->forget(self::KEY);
    }

    /** What `$actor` stored, still checked or not; null when there is none or it is not one this class wrote. */
    public function storedFor(User $actor): ?ViewAsTarget
    {
        $stored = $this->session->get(self::KEY);
        if (! is_array($stored) || ($stored['admin'] ?? null) !== $actor->id) {
            return null;
        }

        return $this->targetOf($stored);
    }

    private function viaUnreadable(User $actor): bool
    {
        $stored = $this->session->get(self::KEY);

        return is_array($stored) && ($stored['via'] ?? null) !== null && $this->storedVia($actor) === null;
    }

    /** What `$actor` acted as when they stored their choice, if they were acting as someone then; null otherwise. */
    public function storedVia(User $actor): ?ViewAsTarget
    {
        $stored = $this->session->get(self::KEY);
        if (! is_array($stored) || ($stored['admin'] ?? null) !== $actor->id || ! is_array($stored['via'] ?? null)) {
            return null;
        }

        return $this->targetOf($stored['via']);
    }

    /**
     * Who `$actor` is acting as on this request, or null. A stored choice that no longer holds (an Admin lost the flag;
     * a leader lost their command, or the person left their scope, the Cav, or became an Admin, or was deleted; the
     * unit was deleted; what an Admin acted through no longer holds) is dropped from the session.
     */
    public function target(User $actor): ?Acting
    {
        $stored = $this->storedFor($actor);
        $via = $this->storedVia($actor);
        // A `via` that is there but unreadable is not "acted as nobody", which would lift the leader's ceiling: drop it.
        $acting = $stored === null || $this->viaUnreadable($actor) ? null : match ($stored->kind) {
            ViewAsKind::User => $this->asPerson($actor, $stored->id, $via),
            ViewAsKind::Unit => $this->asBillet($actor, $stored->id, $via),
            // Never stored: acting as a trooper with no account is stored as their billet.
            ViewAsKind::Trooper => null,
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

    private function asPerson(User $actor, int $id, ?ViewAsTarget $via): ?Acting
    {
        $user = $this->users->find($id);

        return $user !== null && $this->allows($actor, $user, $via) ? new Acting($this->withoutAdmin($user), $user, null, $this->label($user)) : null;
    }

    private function asBillet(User $actor, int $id, ?ViewAsTarget $via): ?Acting
    {
        $unit = $this->units->find($id);

        return $unit !== null && $this->allowsBillet($actor, $via) ? new Acting($this->standIn($actor), null, $unit, $unit->billet()) : null;
    }

    /**
     * The units a start is measured from now: what the identity `$actor` acts from commands. Null when there is none
     * to measure from, an Admin acting as nobody (not held to a scope) or a chain that must not grow.
     *
     * @return Collection<int, Unit>|null
     */
    private function startingPoint(User $actor): ?Collection
    {
        return $this->nested($actor) ? null : $this->commandOf($actor, $this->viaFor($actor));
    }

    /**
     * What `$actor` commands as the starting point for who they may act as: null for an Admin acting as nobody, who is
     * not held to a scope; otherwise the units of the identity they act from, none (so nobody) when that identity is no
     * longer valid or `$actor` is not an Admin with something to act through.
     *
     * @return Collection<int, Unit>|null
     */
    private function commandOf(User $actor, ?ViewAsTarget $via): ?Collection
    {
        if ($via === null) {
            return $actor->is_admin ? null : $this->scope->commandedBy($actor);
        }

        if (! $actor->is_admin) {
            return collect();
        }

        if ($via->kind === ViewAsKind::Unit) {
            return collect([$this->units->find($via->id)])->filter()->values();
        }

        $through = $this->users->find($via->id);

        return $through !== null && $this->allows($actor, $through)
            ? $this->scope->commandedBy($this->withoutAdmin($through))
            : collect();
    }

    /**
     * Whether `$user` is a serving billet holder strictly below the `$commanded` units: their synced billet (the
     * serving roster only) commands a unit below them, and nothing else they hold, an acting lead or a grant, reaches
     * outside what is below. Never an Admin. So acting as them gives no scope the leader does not have.
     *
     * Nor anyone whose command covers the leader's own placement (`$placement`, the leader's trooper's primary unit):
     * an acting lead of the platoon their own section sits in is above their Section Leader by command, but that SL
     * commands them, and every rule keyed on the person themselves ("nobody counsels themselves") would then be asked
     * about the SL instead of the leader.
     *
     * @param  Collection<int, Unit>  $commanded
     */
    private function leadsBelow(Collection $commanded, User $user, ?int $placement): bool
    {
        if ($user->is_admin || $user->cav_id === null) {
            return false;
        }

        $below = $this->scope->strictlyBelow($commanded);
        $billet = $this->commands->commandedUnitFor($user);

        return $billet !== null
            && $below->contains($billet->id)
            && $this->withinAndNotOver($this->scope->realScope($user), $below, $placement);
    }

    /**
     * Whether a person's real scope stays inside what is below the leader and does not cover the leader's own
     * placement: the two reach checks {@see leadsBelow()} and {@see candidates()} share.
     *
     * @param  Collection<int, int>  $scope
     * @param  Collection<int, int>  $below
     */
    private function withinAndNotOver(Collection $scope, Collection $below, ?int $placement): bool
    {
        return $scope->diff($below)->isEmpty() && ($placement === null || ! $scope->contains($placement));
    }

    /**
     * The person whose command a start is measured from: `$actor` themselves when they act as nobody, the leader an
     * Admin acts through, or nobody (an Admin acting as nobody or as a billet, who has no placement to protect there).
     */
    private function measuredFrom(User $actor, ?ViewAsTarget $via): ?User
    {
        if ($via === null) {
            return $actor->is_admin ? null : $actor;
        }

        return $via->kind === ViewAsKind::User && $actor->is_admin ? $this->users->find($via->id) : null;
    }

    /** The primary unit `$user`'s trooper is placed in, or null for nobody, an unlinked user or an unplaced trooper. */
    private function placementOf(?User $user): ?int
    {
        $cavId = $user?->cav_id;

        return $cavId === null ? null : $this->troopers->byCavId([(int) $cavId])->get((int) $cavId)?->primary_section_id;
    }

    /** @param  array<mixed>  $stored */
    private function targetOf(array $stored): ?ViewAsTarget
    {
        if (! is_int($stored['id'] ?? null)) {
            return null;
        }

        $kind = ViewAsKind::tryFrom((string) ($stored['kind'] ?? ''));

        return $kind ? new ViewAsTarget($kind, $stored['id']) : null;
    }
}
