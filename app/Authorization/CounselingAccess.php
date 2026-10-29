<?php

namespace App\Authorization;

use App\Models\CommandRole;
use App\Models\CounselingType;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitActingLeadRepository;
use App\Repositories\UnitCommandMappingRepository;
use Illuminate\Support\Collection;

/**
 * Who may file, see, correct and delete a trooper's counseling records (#381, AD-8), decided once per viewer and
 * trooper on top of the {@see ScopeResolver}, the way the regulations and the product owner (2026-10-28) put it:
 *
 *  - **File a DCS**: any leader whose command (billet, acting lead, a held billet, never a staff grant) reaches the
 *    trooper's unit: Section Leader and up, portfolio officers over their departments included. **File an LOR**: only
 *    command from platoon level up (Platoon Leader and Sergeant, company, battalion and regiment command, an acting
 *    lead of a platoon or above). An Admin may file either.
 *  - A command the trooper shares, or one inside the trooper's own command, does not count: a leader never files on,
 *    or sees the DCSs of, someone who holds the same unit (an ASL and their Section Leader, a Platoon Sergeant and their
 *    Platoon Leader) or a unit above theirs (the Section Leader of a trooper who is acting lead of their platoon), and
 *    nobody files on themselves. The next echelon up does.
 *  - **Their own records**: every trooper sees their own DCSs and LORs, read-only, on their own milpac page
 *    ({@see ScopeResolver::isOwnTrooper()}, decided 2026-10-28), with no command and no grant. They never file, correct
 *    or delete their own; that refusal is the same as before, except that an Admin by the `users.is_admin` flag keeps
 *    changing records as an Admin, their own included (an Admin by billet does not, 2026-10-29).
 *  - "Themselves" is also the person really signed in while acting as someone ({@see RealActor}): acting as a billet
 *    or as a person never lets anyone file on, see the DCSs of, or change the records of the one doing the acting:
 *    their own page shows them their DCSs only as themselves, not through the identity they act as.
 *  - **See a DCS**: Admins, and every leader who could file one on the trooper, which is the chain from Platoon
 *    Leader up plus the trooper's own Section Leader and ASL. A staff grant does not. **See an LOR**: everyone who
 *    can see the trooper ({@see ScopeResolver::canSee()}). Nothing is seen of a trooper the viewer cannot see, but
 *    their own.
 *  - **Correct or delete**: Admins, and the commanders of the unit the record was filed through (the issuer and the
 *    chain above them), as long as they still see the record. A record with no issuing unit (filed by an Admin, or its
 *    unit deleted) is the Admins' alone.
 *  - **A frozen LOR** (S6, 2026-10-29): 72 hours after it is filed, its appeal window, nobody corrects or deletes it,
 *    Admins included ({@see CounselingPermissions::frozen()}). Only the **Regimental Executive Officer** (the serving
 *    holder of the billet titled so, which the org import maps to the regiment) may then overturn it, with a reason; it
 *    stays on file. Never their own, and never in a view-as preview. Whoever may act as them (an Admin, or the RCO as
 *    the senior of their command team) may overturn as them, and is named beside them. Acting as a billet never can:
 *    a held billet is the unit's lead, not its XO. A DCS is unchanged.
 *  - **A trooper who has left the Cav** (not serving, {@see TrooperStatus::serving()}): nobody files, corrects or
 *    deletes, Admins included (2026-10-28). Who could see the records still sees and downloads them. Serving again
 *    makes them writable again.
 *
 * Writes are judged on real authority narrowed by an active view-as preview ({@see ScopeResolver::writeScope()}); the
 * `ReadOnlyWhileViewingAs` middleware refuses them during a preview anyway. Reads follow the preview through `canSee`,
 * and an Admin's DCS read through {@see ScopeResolver::actsAsAdmin()}, so previewing a non-Admin shows what they see.
 */
class CounselingAccess
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitCommandMappingRepository $commands,
        private readonly UnitActingLeadRepository $actingLeads,
        private readonly RealActor $real,
        private readonly TrooperRepository $troopers,
    ) {}

    public function for(User $user, Trooper $trooper): CounselingPermissions
    {
        $section = $trooper->primary_section_id;
        $self = $this->isSelf($user, $trooper);
        $canSee = $this->scope->canSee($user, $trooper);
        // Their own records, read-only, whatever their scope (2026-10-28): never a write, which $self refuses below.
        $own = $this->scope->isOwnTrooper($user, $trooper);
        // An Admin by flag or by an approved Admin billet (ScopeResolver::isAdmin()).
        $admin = $this->scope->isAdmin($user);
        $writeScope = $section === null && ! $admin ? collect() : $this->scope->writeScope($user);

        $issuing = [CounselingType::Dcs->value => null, CounselingType::Lor->value => null];
        if ($section !== null && ! $self && $writeScope->contains($section)) {
            $reaching = $this->reachingUnits($user, $trooper, $section);
            $issuing[CounselingType::Dcs->value] = $reaching->first();
            $issuing[CounselingType::Lor->value] = $reaching->first(fn (Unit $unit) => self::platoonOrAbove($unit));
        }

        // Left the Cav (not on the serving allow-list, or no standing at all): read-only for everyone (2026-10-28).
        $departed = ! ($trooper->status?->isServing() ?? false);

        // A read follows a view-as preview: an Admin previewing someone who is not one sees the DCSs that person's
        // command would show, not every DCS. Writes keep the real flag; a preview refuses them anyway.
        $readsAsAdmin = $this->scope->actsAsAdmin($user);

        return new CounselingPermissions(
            seesDcs: $own || ($canSee && ($readsAsAdmin || $issuing[CounselingType::Dcs->value] !== null)),
            seesLor: $own || $canSee,
            filesDcs: ! $departed && ! $self && ($admin || $issuing[CounselingType::Dcs->value] !== null),
            filesLor: ! $departed && ! $self && ($admin || $issuing[CounselingType::Lor->value] !== null),
            admin: $admin,
            self: $self,
            departed: $departed,
            issuingUnits: array_map(fn (?Unit $unit) => $unit?->id, $issuing),
            writeScope: $writeScope->map(fn ($id) => (int) $id)->values()->all(),
            // The request's own user: acting as a person or a billet, that is a copy with the flag off.
            flagAdmin: (bool) $user->is_admin,
            regimentalXo: ! $self && $this->isRegimentalXo($user),
        );
    }

    /**
     * Whether the identity the request runs as holds the Regimental Executive Officer billet now, outside a view-as
     * preview: their synced title is that billet's (as {@see CommandRole} reads it) and the billet commands the regiment,
     * which {@see UnitCommandMappingRepository::commandedUnitFor()} answers only while they serve. One query for anyone
     * whose title is something else, a second for the RXO.
     */
    private function isRegimentalXo(User $user): bool
    {
        if ($user->cav_id === null || $this->scope->activeViewAs($user) !== null) {
            return false;
        }

        $trooper = $this->troopers->byCavId([(int) $user->cav_id])->get((int) $user->cav_id);
        if (CommandRole::fromBillet($trooper?->billet, UnitType::Regiment) !== CommandRole::Xo) {
            return false;
        }

        return $this->commands->commandedUnitFor($user)?->type === UnitType::Regiment;
    }

    /**
     * Whether `$trooper` is the viewer: the identity the request runs as, or the person really signed in while acting
     * as someone ({@see RealActor}). A billet's stand-in has no milpac link of its own, and a person acted as is
     * somebody else, so without the second check an Admin acting as a billet could file on themselves.
     */
    private function isSelf(User $user, Trooper $trooper): bool
    {
        return collect([$user->cav_id, $this->real->get()?->cav_id])
            ->filter(fn ($cavId) => $cavId !== null)
            ->contains(fn ($cavId) => (int) $cavId === (int) $trooper->cav_id);
    }

    /**
     * The units through which the user's command reaches the trooper's unit, lowest first, without the ones inside the
     * trooper's own command: a unit the trooper commands (billet or acting lead), and every unit below it. A peer's
     * shared billet is not a chain of command over them, and neither is a unit they lead themselves (the Section
     * Leader of a trooper made acting Platoon Leader of their own platoon is below them, not above).
     *
     * Every reaching unit contains the trooper's unit, so it lies on that unit's ancestor chain, and so does any unit
     * of the trooper's that contains it: the ids in the reaching units' paths are the only ones worth asking about.
     *
     * @return Collection<int, Unit>
     */
    private function reachingUnits(User $user, Trooper $trooper, int $section): Collection
    {
        $units = $this->scope->commandingUnitsOver($user, $section);
        if ($units->isEmpty()) {
            return $units;
        }

        $chain = $units->flatMap(fn (Unit $unit) => self::pathIds($unit))->unique()->values();

        $held = collect();
        if ($trooper->cav_position_id !== null) {
            $held->push($this->commands->unitIdsByPositionId()->get($trooper->cav_position_id));
        }
        $this->actingLeads->leadsOf($chain->all())
            ->filter(fn (Trooper $lead) => $lead->id === $trooper->id)
            ->each(fn (Trooper $lead, int $unitId) => $held->push($unitId));
        $held = $held->filter()->map(fn ($id) => (int) $id);

        return $units->reject(fn (Unit $unit) => self::pathIds($unit)->intersect($held)->isNotEmpty())->values();
    }

    /**
     * The ids from the root down to `$unit`, itself last, read from its materialized path (`1/5/9/`).
     *
     * @return Collection<int, int>
     */
    private static function pathIds(Unit $unit): Collection
    {
        return collect(explode('/', (string) $unit->path))
            ->filter(fn (string $id) => $id !== '')
            ->map(fn (string $id) => (int) $id)
            ->values();
    }

    /** A line unit at platoon level or above: what an LOR's issuer must command (a staff group does not count). */
    private static function platoonOrAbove(Unit $unit): bool
    {
        return $unit->type->isLine() && $unit->type->rank() <= UnitType::Platoon->rank();
    }
}
