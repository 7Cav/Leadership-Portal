<?php

namespace App\Models;

/**
 * A cached trooper's roster standing. `Combat`/`Reserve`/`Eloa` mirror the three serving Cav
 * rosters the sync handler sweeps; `Inactive` is the one value the handler assigns itself, for
 * a trooper who has disappeared from all of them (AD-4) — it is never reported by Cav.
 *
 * Deliberately not naming the Integration-layer roster-type enum here — Deptrac forbids
 * Model → Integration, and pint's `fully_qualified_strict_types` fixer turns any `{@see}`
 * reference into a real `use` import, which would quietly create that reverse edge.
 */
enum TrooperStatus: string
{
    case Combat = 'combat';
    case Reserve = 'reserve';
    case Eloa = 'eloa';
    case Inactive = 'inactive';

    /**
     * The standings that count as still in the Cav — an allow-list, so `Inactive`, or any
     * standing added later, confers nothing until someone decides it should. The one definition
     * every "is this person serving" question reads: roster-derived command scope (1.16), whether
     * a staff grant still counts, and who may sign in at all.
     *
     * @return list<self>
     */
    public static function serving(): array
    {
        return [self::Combat, self::Reserve, self::Eloa];
    }

    /**
     * {@see serving()} as the stored column values, for a `whereIn`.
     *
     * @return list<string>
     */
    public static function servingValues(): array
    {
        return array_map(fn (self $status) => $status->value, self::serving());
    }

    public function isServing(): bool
    {
        return in_array($this, self::serving(), true);
    }
}
