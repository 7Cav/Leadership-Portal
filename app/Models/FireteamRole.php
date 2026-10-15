<?php

namespace App\Models;

/**
 * A trooper's place in their section's fireteams (`fireteam_assignments.role`): a fireteam leader, or a member of one
 * FTL's fireteam. Unofficial, set by the section's leaders; Cav does not know it.
 */
enum FireteamRole: string
{
    case Leader = 'ftl';
    case Member = 'member';
}
