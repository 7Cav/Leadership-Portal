<?php

namespace App\Repositories;

/**
 * The materialized-path convention for `units.path` (AD-18): a path is the unit's ancestor ids and
 * its own id, root first, each followed by a slash ("1/", "1/2/", "1/2/145/").
 * `UnitRepository::create()` writes paths in this shape.
 *
 * Pure string logic with no DB access, so it is unit-tested directly rather than only through the
 * repository queries that use it.
 */
final class UnitPath
{
    /**
     * Depth-first order over paths, for `usort`: compare segment by segment as numbers, and a path
     * that is a prefix of another (an ancestor) comes first. Siblings therefore keep id order, which
     * a text sort (`ORDER BY path`) breaks once ids pass a digit boundary ("1/145/" would sort
     * before "1/2/").
     */
    public static function compare(string $a, string $b): int
    {
        $left = array_map('intval', explode('/', trim($a, '/')));
        $right = array_map('intval', explode('/', trim($b, '/')));

        foreach ($left as $index => $segment) {
            if (! isset($right[$index])) {
                return 1;
            }

            if ($segment !== $right[$index]) {
                return $segment <=> $right[$index];
            }
        }

        return count($left) <=> count($right);
    }
}
