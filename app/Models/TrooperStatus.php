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
}
