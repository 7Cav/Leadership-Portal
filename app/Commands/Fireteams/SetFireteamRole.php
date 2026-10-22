<?php

namespace App\Commands\Fireteams;

use App\Models\FireteamRole;

/**
 * Give a trooper their place in their section's fireteams: a fireteam leader (FTL), a member of the fireteam
 * `$leaderTrooperId` leads, or (a null `$role`) neither. FTLs are unofficial, so this is the only place they come from.
 * Handled by {@see SetFireteamRoleHandler}.
 */
final readonly class SetFireteamRole
{
    public function __construct(
        public int $sectionId,
        public int $trooperId,
        public ?FireteamRole $role,
        public ?int $leaderTrooperId = null,
    ) {}
}
