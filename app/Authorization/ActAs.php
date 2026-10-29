<?php

namespace App\Authorization;

use App\Models\CommandRole;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitActingLeadRepository;
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
 * Inside a unit's own command team a senior may also act as everyone junior to them in it ({@see juniorInTeam()}): the
 * RCO as the RXO and the RCSM, the RXO as the RCSM, a company CO as the XO and
 * the 1SG, a PL as the PSG, an SL as the ASL, by {@see CommandRole::seniority()}. Never the other way, never a peer.
 * That person commands the same unit, so their command covers the leader's placement; the exception to the check
 * above is for them alone, and only while all they reach stays inside that unit. An acting lead stands where the
 * billet they act for does: the lead of the unit, senior to the rest of its team.
 *
 * An Admin who already acts as someone ("via") is held to that identity's reach when they start another: acting as a
 * leader, they may start only what the leader could, and the pair is checked together on every request, so what they
 * reach through a leader can never exceed that leader's scope. They stop to be an Admin again. The session key's
 * `admin` is the real signed-in user, who for a leader is not an Admin.
 *
 * "Admin" here is always {@see ScopeResolver::isAdmin()}: the flag or an approved Regimental HQ or S6 HQ billet, so an
 * Admin by billet acts as anyone a flag Admin may, a leader can never act as one, and an Admin
 * acting as one gets that person's non-Admin view only, exactly as for a flag Admin.
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
        private readonly UnitActingLeadRepository $actingLeads,
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
     * below what that identity commands ({@see leadsBelow()}), or junior to them in a command team they are part of
     * ({@see juniorInTeam()}).
     */
    public function allows(User $actor, User $user, ?ViewAsTarget $via = null): bool
    {
        if ($user->id === $actor->id || $this->serving->mustEnd($user)) {
            return false;
        }

        $commanded = $this->commandOf($actor, $via);
        if ($commanded === null) {
            return true;
        }

        $from = $this->measuredFrom($actor, $via);
        $trooper = $this->trooperOf($from);

        return $this->leadsBelow($commanded, $user, $trooper?->primary_section_id)
            || $this->juniorInTeam($commanded, $from, $trooper, $user);
    }

    /** Whether `$actor` may act as the leader of a unit: a real Admin acting as nobody, for any unit, vacant or held. */
    public function allowsBillet(User $actor, ?ViewAsTarget $via = null): bool
    {
        return $this->commandOf($actor, $via) === null;
    }

    /**
     * Whether `$actor`, signed in as themselves, holds the authority to act as anyone: an Admin, someone who commands a
     * unit with units below it, or a senior with someone junior to them in their command team. A staff grant never
     * does. The gate before {@see allows()}, which says who exactly.
     */
    public function leads(User $actor): bool
    {
        if ($this->scope->isAdmin($actor)) {
            return true;
        }

        $commanded = $this->scope->commandedBy($actor);

        return $this->scope->strictlyBelow($commanded)->isNotEmpty() || $this->hasJuniorInTeam($actor, $commanded);
    }

    /**
     * Whether a picker for acting as someone should be offered to `$actor` now: a leader, or an Admin acting as one, who
     * has units below them to start from or someone junior to them in their command team. Never to an Admin acting as
     * nobody (view-as leads them to the person) nor to someone acting as a person who is not an Admin (no chains).
     */
    public function offers(User $actor): bool
    {
        $via = $this->viaFor($actor);
        $commanded = $this->startingPoint($actor, $via);

        return $commanded !== null
            && ($this->scope->strictlyBelow($commanded)->isNotEmpty() || $this->hasJuniorInTeam($this->measuredFrom($actor, $via), $commanded));
    }

    /**
     * Who `$actor` may act as right now: serving billet holders strictly below what they command, and those junior to
     * them in their own command team, as the picker lists them, in username order. Empty when {@see offers()} is false.
     *
     * @return Collection<int, User>
     */
    public function candidates(User $actor): Collection
    {
        $via = $this->viaFor($actor);
        $commanded = $this->startingPoint($actor, $via);
        if ($commanded === null || $commanded->isEmpty()) {
            return collect();
        }

        // A flag Admin is left out here; an Admin by billet below, as realScopes() gives them the whole tree, which never
        // fits below a leader (withinAndNotOver()). A team junior who is an Admin by billet (a regimental command team
        // billet an Admin approved) is left out by the subtree check below, as their scope is the whole tree.
        $below = $this->scope->strictlyBelow($commanded);
        $from = $this->measuredFrom($actor, $via);
        $fromCavId = $from?->cav_id === null ? null : (int) $from->cav_id;
        $others = $this->users->all()->reject(fn (User $user) => $user->id === $actor->id || $user->is_admin || $user->cav_id === null
            || (int) $user->cav_id === $fromCavId);
        $held = $this->commands->commandedUnitsByCavId($others->pluck('cav_id')->push($fromCavId)->filter()->values()->all());

        // The leader's own trooper (placement and billet title) and the trooper of everyone on a billet of a unit the
        // leader commands (their titles say who is junior), in one read.
        $commandedIds = $commanded->pluck('id');
        $teamCavIds = $others->filter(fn (User $user) => $commandedIds->contains($held->get($user->cav_id)?->id))->pluck('cav_id');
        $troopers = $this->troopers->byCavId($teamCavIds->push($fromCavId)->filter()->map(fn ($id) => (int) $id)->unique()->values()->all());
        $trooper = $fromCavId === null ? null : $troopers->get($fromCavId);
        $roles = $from === null ? collect() : $this->teamRoles($from, $trooper, $fromCavId === null ? null : $held->get($fromCavId), $commanded);
        if ($below->isEmpty() && $roles->isEmpty()) {
            return collect();
        }

        $placement = $trooper?->primary_section_id;
        $billeted = $others->filter(function (User $user) use ($held, $below, $roles, $troopers) {
            $unit = $held->get($user->cav_id);

            return $unit !== null && ($below->contains($unit->id)
                || ($roles->has($unit->id) && $this->isJunior($roles->get($unit->id), $unit, $troopers->get((int) $user->cav_id))));
        });

        // The same checks as allows(), each person's standing and reach read for all of them at once (#496): a fixed
        // number of queries however many people are below, not a batch per person. Each unit the leader holds a team
        // billet in adds one subtree read, whatever the number of people.
        $ending = $this->serving->mustEndAmong($billeted)->flip();
        $scopes = $this->scope->realScopes($billeted);
        $subtrees = $commanded->filter(fn (Unit $unit) => $roles->has($unit->id))->unique('id')
            ->mapWithKeys(fn (Unit $unit) => [$unit->id => $this->units->subtreeIds($unit)]);

        return $billeted
            ->filter(function (User $user) use ($ending, $scopes, $held, $below, $placement, $subtrees) {
                $scope = $scopes->get($user->id, collect());
                $unit = $held->get($user->cav_id);

                return ! $ending->has($user->id) && ($below->contains($unit->id)
                    ? $this->withinAndNotOver($scope, $below, $placement)
                    : $scope->diff($subtrees->get($unit->id, collect()))->isEmpty());
            })
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
     * milpac link, so none of their own billets, acting leads or grants count, only the billet ({@see ActingBillet}),
     * and never an Admin by billet either. Its attributes are its original ones, so nothing is dirty: a save, should
     * anything ever try one, writes nothing.
     */
    public function standIn(User $admin): User
    {
        $standIn = clone $admin;
        $standIn->setRawAttributes(['is_admin' => false, 'cav_id' => null] + $admin->getAttributes(), sync: true);
        $standIn->notAdminByBillet = true;

        return $standIn;
    }

    /**
     * The person as acted as: unchanged, or for an Admin (by flag or by billet), their account with the Admin flag off
     * and never an Admin by billet, so what they command by billet, acting lead or grant counts and nothing an Admin may
     * do. Never dirty, like {@see standIn()}.
     */
    public function withoutAdmin(User $user): User
    {
        if (! $this->scope->isAdmin($user)) {
            return $user;
        }

        $plain = clone $user;
        $plain->setRawAttributes(['is_admin' => false] + $user->getAttributes(), sync: true);
        $plain->notAdminByBillet = true;

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
     * The units a start is measured from now: what the identity `$actor` acts from (`$via`, from {@see viaFor()})
     * commands. Null when there is none to measure from, an Admin acting as nobody (not held to a scope) or a chain
     * that must not grow.
     *
     * @return Collection<int, Unit>|null
     */
    private function startingPoint(User $actor, ?ViewAsTarget $via): ?Collection
    {
        return $this->nested($actor) ? null : $this->commandOf($actor, $via);
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
            return $this->scope->isAdmin($actor) ? null : $this->scope->commandedBy($actor);
        }

        if (! $this->scope->isAdmin($actor)) {
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
        if ($user->cav_id === null || $this->scope->isAdmin($user)) {
            return false;
        }

        $below = $this->scope->strictlyBelow($commanded);
        $billet = $this->commands->commandedUnitFor($user);

        return $billet !== null
            && $below->contains($billet->id)
            && $this->withinAndNotOver($this->scope->realScope($user), $below, $placement);
    }

    /**
     * Whether `$user` holds a billet in the command team of a unit the leader `$from` commands, strictly junior to the
     * leader's own billet in that team ({@see teamRoles()}): the RXO for the RCO, the ASL for an SL. Their command
     * covers the leader's placement, as it covers the whole unit, so the check {@see leadsBelow()} makes gives way to a
     * narrower one: all they reach (a grant, an acting lead) stays inside that unit. Never an Admin, never the leader
     * themselves. Billet titles decide, so a holder whose title names no team billet is never junior.
     *
     * @param  Collection<int, Unit>  $commanded
     */
    private function juniorInTeam(Collection $commanded, ?User $from, ?Trooper $trooper, User $user): bool
    {
        if ($from === null || $commanded->isEmpty() || $user->cav_id === null || (int) $user->cav_id === (int) $from->cav_id || $this->scope->isAdmin($user)) {
            return false;
        }

        $unit = $this->commands->commandedUnitFor($user);
        if ($unit === null) {
            return false;
        }

        $mine = $this->teamRoles($from, $trooper, $this->commands->commandedUnitFor($from), $commanded)->get($unit->id);
        $theirs = $this->troopers->byCavId([(int) $user->cav_id])->get((int) $user->cav_id);

        return $mine !== null
            && $this->isJunior($mine, $unit, $theirs)
            && $this->scope->realScope($user)->diff($this->units->subtreeIds($unit))->isEmpty();
    }

    /**
     * Whether anyone serving holds a billet junior to `$from` in a command team they are part of: what makes a Section
     * Leader with an ASL, who has no units below, a leader who may act as someone. One read of those teams.
     *
     * @param  Collection<int, Unit>  $commanded
     */
    private function hasJuniorInTeam(?User $from, Collection $commanded): bool
    {
        if ($from === null || $commanded->isEmpty()) {
            return false;
        }

        $trooper = $this->trooperOf($from);
        $roles = $this->teamRoles($from, $trooper, $this->commands->commandedUnitFor($from), $commanded);
        if ($roles->isEmpty()) {
            return false;
        }

        $units = $commanded->keyBy('id');

        return $this->commands->teamsOf($roles->keys()->all())->contains(
            fn (Collection $team, int $unitId) => $team->contains(
                fn (Trooper $member) => $member->id !== $trooper?->id && $this->isJunior($roles->get($unitId), $units->get($unitId), $member),
            ),
        );
    }

    /**
     * The billet `$from` holds in the command team of each unit they command, keyed by unit id: the unit's lead (CO, PL,
     * SL) where they are its acting lead, where the roster tags them ("Acting PL") and the billet they act for stands,
     * otherwise the billet their own title names in the unit their synced billet commands (`$billet`, read by the
     * caller with their `$trooper`). A unit where they hold no team billet is absent, a staff portfolio's group among
     * them: a group has no team.
     *
     * @param  Collection<int, Unit>  $commanded
     * @return Collection<int, CommandRole>
     */
    private function teamRoles(User $from, ?Trooper $trooper, ?Unit $billet, Collection $commanded): Collection
    {
        $roles = collect();
        if ($billet !== null && ($role = CommandRole::fromBillet($trooper?->billet, $billet->type)) !== null) {
            $roles->put($billet->id, $role);
        }

        foreach ($this->actingLeads->unitsLedBy($from) as $unit) {
            if (($lead = CommandRole::leadOf($unit->type)) !== null) {
                $roles->put($unit->id, $lead);
            }
        }

        $ids = $commanded->pluck('id');

        return $roles->filter(fn (CommandRole $role, int $unitId) => $ids->contains($unitId));
    }

    /** Whether `$theirs`, on a billet commanding `$unit`, is strictly junior to `$mine` in that unit's command team. */
    private function isJunior(CommandRole $mine, Unit $unit, ?Trooper $theirs): bool
    {
        $role = CommandRole::fromBillet($theirs?->billet, $unit->type);

        return $role !== null && $mine->isSeniorTo($role);
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
     * Admin acts through, or nobody (an Admin acting as a billet, who has no placement to protect there). Only asked
     * once {@see commandOf()} has found units to measure from, which it never does for an Admin acting as nobody, so
     * acting as nobody it is always `$actor` (and the Admin rule is not read twice).
     */
    private function measuredFrom(User $actor, ?ViewAsTarget $via): ?User
    {
        if ($via === null) {
            return $actor;
        }

        return $via->kind === ViewAsKind::User && $this->scope->isAdmin($actor) ? $this->users->find($via->id) : null;
    }

    /**
     * `$user`'s trooper, or null for nobody or an unlinked user: where they are placed (`primary_section_id`, their
     * primary unit) and their billet title.
     */
    private function trooperOf(?User $user): ?Trooper
    {
        $cavId = $user?->cav_id;

        return $cavId === null ? null : $this->troopers->byCavId([(int) $cavId])->get((int) $cavId);
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
