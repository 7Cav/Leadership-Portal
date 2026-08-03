<?php

namespace App\Integration\Cav\Data;

/**
 * The 7Cav rosters, as api.7cav.us names them on the wire.
 *
 * Modelling this as an enum rather than a string keeps the wire token from leaking past the
 * seam in either direction: nothing outside `Integration/` sees `ROSTER_TYPE_COMBAT`, and no
 * caller can interpolate an arbitrary string into a roster URL path.
 */
enum RosterType: string
{
    case Combat = 'ROSTER_TYPE_COMBAT';
    case Reserve = 'ROSTER_TYPE_RESERVE';
    case Eloa = 'ROSTER_TYPE_ELOA';
    case WallOfHonor = 'ROSTER_TYPE_WALL_OF_HONOR';
    case Arlington = 'ROSTER_TYPE_ARLINGTON';
    case PastMembers = 'ROSTER_TYPE_PAST_MEMBERS';

    /**
     * The rosters a trooper who can sign in to the portal may appear on, most likely first —
     * the enrichment sweep stops at the first hit, so the common case costs one call. The
     * memorial and past-member rosters are excluded: nobody on them can log in.
     */
    public static function serving(): array
    {
        return [self::Combat, self::Reserve, self::Eloa];
    }

    /**
     * Human-readable name for display. The API's `ROSTER_TYPE_UNSPECIFIED` member is
     * deliberately absent from this enum — `tryFrom()` maps it (and any roster type added
     * to the API later) to null, which carries the same information without a magic string.
     */
    public function label(): string
    {
        return match ($this) {
            self::Combat => 'Combat',
            self::Reserve => 'Reserve',
            self::Eloa => 'ELOA',
            self::WallOfHonor => 'Wall of Honor',
            self::Arlington => 'Arlington',
            self::PastMembers => 'Past Members',
        };
    }
}
