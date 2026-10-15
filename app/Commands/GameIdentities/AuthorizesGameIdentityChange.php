<?php

namespace App\Commands\GameIdentities;

use App\Authorization\ScopeResolver;
use App\Models\Trooper;
use App\Models\User;
use App\Repositories\TrooperRepository;

/**
 * The scope gate shared by the link and unlink handlers (AD-8): a trooper may be changed only when
 * their primary section is in the actor's write scope — command authority, never a staff grant, and
 * a view-as preview narrows it, so a previewing leader cannot reach further than the target they are
 * viewing as.
 */
trait AuthorizesGameIdentityChange
{
    /**
     * @throws GameIdentityForbiddenException
     */
    private function authorizedTrooper(User $actor, int $trooperId, ScopeResolver $scope, TrooperRepository $troopers): Trooper
    {
        $trooper = $troopers->find($trooperId);

        if ($trooper === null
            || ! $trooper->status->isServing()
            || $trooper->primary_section_id === null
            || ! $scope->writeScope($actor)->contains($trooper->primary_section_id)) {
            throw GameIdentityForbiddenException::forTrooper($trooperId);
        }

        return $trooper;
    }
}
