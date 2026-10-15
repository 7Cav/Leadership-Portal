<?php

namespace App\Models;

/**
 * Where a section's fireteam leaders go in its rollcall post, once FTLs are shown: after the SL and ASL, either every
 * FTL above the rest of the section (`LeadersFirst`, the default), or each FTL directly above the members of their
 * fireteam, with troopers in no fireteam last (`ByFireteam`).
 */
enum FireteamOrder: string
{
    case LeadersFirst = 'leaders_first';
    case ByFireteam = 'by_fireteam';
}
