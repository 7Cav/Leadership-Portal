<?php

namespace App\Commands\Fireteams;

use App\Authorization\ScopeResolver;
use App\Models\FireteamAssignment;
use App\Models\FireteamRole;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\FireteamRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitCommandMappingRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;
use Illuminate\Support\Collection;

/**
 * Sets a trooper's fireteam role in their section as one audited action (AD-16): `fireteam.leader_set`,
 * `fireteam.member_assigned`, `fireteam.leader_unset` (which also takes the FTL's members out of the fireteam) or
 * `fireteam.member_unassigned`. Taking out a trooper who is in no fireteam changes nothing and logs nothing.
 *
 * Only a leader who commands the section may do it ({@see ScopeResolver::commandsUnit()}: its SL or ASL, the platoon
 * or company command above it, or an Admin); a staff grant reads the rollcall but does not change it. Both troopers
 * must be placed in the section now; a member's leader must already be one of its FTLs; the SL and ASL are always
 * listed first, so they are neither FTLs nor members; and an FTL is unmarked before joining another fireteam.
 */
class SetFireteamRoleHandler
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly UnitCommandMappingRepository $commands,
        private readonly FireteamRepository $fireteams,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @return FireteamAssignment|null the row written or removed; null when there was nothing to remove.
     *
     * @throws FireteamForbiddenException when the actor does not command the section (or it does not exist).
     * @throws InvalidFireteamException when the change breaks one of the rules above.
     */
    public function handle(User $actor, SetFireteamRole $command): ?FireteamAssignment
    {
        $section = $this->commandedSection($actor, $command->sectionId);
        $placed = $this->troopers->placedIn($section->id)->keyBy('id');
        $trooper = $placed->get($command->trooperId) ?? throw InvalidFireteamException::notPlaced($command->trooperId);

        if ($command->role === null) {
            return $this->remove($actor, $section, $trooper);
        }

        $sectionLeader = $this->commands->commandersOf([$section->id])->get($section->id);
        $this->refuseSectionLeader($trooper, $sectionLeader);

        if ($command->role === FireteamRole::Leader) {
            return $this->audit->record(
                actorId: $actor->id,
                action: 'fireteam.leader_set',
                reason: null,
                reasonRequired: false,
                mutation: fn () => $this->fireteams->makeLeader($section->id, $trooper->id),
            );
        }

        $leader = $this->memberLeader($command, $placed, $sectionLeader, $section);

        if ($this->fireteams->assignmentsIn($section->id)->get($trooper->id)?->role === FireteamRole::Leader) {
            throw InvalidFireteamException::leadsFireteam($trooper->display_name);
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'fireteam.member_assigned',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->fireteams->makeMember($section->id, $trooper->id, $leader->id),
        );
    }

    /**
     * The section, once the actor is known to command it. A unit that does not exist is refused like one outside the
     * actor's command, so ids cannot be probed (AD-8).
     */
    private function commandedSection(User $actor, int $sectionId): Unit
    {
        $section = $this->units->find($sectionId);

        if ($section === null || ! $this->scope->commandsUnit($actor, $section)) {
            throw new FireteamForbiddenException($sectionId);
        }

        if ($section->type !== UnitType::Section) {
            throw InvalidFireteamException::notASection($sectionId);
        }

        return $section;
    }

    private function refuseSectionLeader(Trooper $trooper, ?Trooper $sectionLeader): void
    {
        $role = $trooper->roleUnder($sectionLeader);

        if ($role !== null) {
            throw InvalidFireteamException::leadsSection($trooper->display_name, $role);
        }
    }

    /**
     * The FTL a new member follows: placed in the section, not the member themselves, and already one of its FTLs.
     *
     * @param  Collection<int, Trooper>  $placed
     */
    private function memberLeader(SetFireteamRole $command, Collection $placed, ?Trooper $sectionLeader, Unit $section): Trooper
    {
        if ($command->leaderTrooperId === null) {
            throw InvalidFireteamException::memberNeedsLeader();
        }

        if ($command->leaderTrooperId === $command->trooperId) {
            throw InvalidFireteamException::ownFireteam();
        }

        $leader = $placed->get($command->leaderTrooperId);

        if ($leader === null
            || $leader->roleUnder($sectionLeader) !== null
            || $this->fireteams->assignmentsIn($section->id)->get($leader->id)?->role !== FireteamRole::Leader) {
            throw InvalidFireteamException::notALeader($command->leaderTrooperId);
        }

        return $leader;
    }

    private function remove(User $actor, Unit $section, Trooper $trooper): ?FireteamAssignment
    {
        $current = $this->fireteams->assignmentsIn($section->id)->get($trooper->id);

        if ($current === null) {
            return null;
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: $current->role === FireteamRole::Leader ? 'fireteam.leader_unset' : 'fireteam.member_unassigned',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->fireteams->remove($section->id, $trooper->id),
        );
    }
}
