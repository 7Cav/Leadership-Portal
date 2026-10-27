<?php

namespace App\Repositories;

use App\Models\Unit;
use Illuminate\Support\Collection;

/**
 * The order 7Cav reads units in (Story 3.7, 3.5 defaults): depth-first like {@see UnitPath::compare()}, but siblings
 * ordered by their designation instead of their id, so "1/1/A/1-7" comes before "2/1/A/1-7" and "A/1-7" before "B/1-7"
 * whatever order the org import happened to create them in. A sibling's designation is the first part of its callsign
 * (its name when it has none): numbered ones first, by number ("2" before "10"), then lettered ones alphabetically, with
 * the id breaking a tie.
 *
 * Pure: it reads only the units it is given, so it is unit-tested directly.
 */
final class UnitDesignationOrder
{
    /**
     * `$units` in designation order. `$tree` supplies their ancestors (every unit named in a path), so siblings anywhere
     * up the chain are compared by designation too; an ancestor missing from it sorts by id.
     *
     * @template TUnit of Unit
     *
     * @param  Collection<int, TUnit>  $units
     * @param  Collection<int, Unit>  $tree
     * @return Collection<int, TUnit>
     */
    public static function sort(Collection $units, Collection $tree): Collection
    {
        $byId = $tree->keyBy('id');
        $keys = $units->mapWithKeys(fn (Unit $unit) => [$unit->id => self::pathKey($unit->path, $byId)]);

        return $units->sort(fn (Unit $a, Unit $b) => self::compareKeys($keys[$a->id], $keys[$b->id]))->values();
    }

    /**
     * How one unit sorts among its siblings: `[0, number, rest]` for a designation that starts with a number, `[1, 0,
     * text]` for any other, upper-cased.
     *
     * @return array{int, int, string}
     */
    public static function siblingKey(?string $callsign, string $name): array
    {
        $designation = trim(explode('/', filled($callsign) ? $callsign : $name)[0]);

        if (preg_match('/^(\d+)(.*)$/', $designation, $match) === 1) {
            return [0, (int) $match[1], mb_strtoupper($match[2])];
        }

        return [1, 0, mb_strtoupper($designation)];
    }

    /**
     * @param  Collection<int, Unit>  $byId
     * @return list<array{array{int, int, string}, int}>
     */
    private static function pathKey(string $path, Collection $byId): array
    {
        $ids = array_map('intval', array_filter(explode('/', $path), fn (string $id) => $id !== ''));

        return array_map(function (int $id) use ($byId) {
            $unit = $byId->get($id);

            return [$unit === null ? [2, 0, ''] : self::siblingKey($unit->callsign, $unit->name), $id];
        }, array_values($ids));
    }

    /**
     * Level by level; an ancestor (a key that is a prefix of the other) comes first.
     *
     * @param  list<array{array{int, int, string}, int}>  $a
     * @param  list<array{array{int, int, string}, int}>  $b
     */
    private static function compareKeys(array $a, array $b): int
    {
        foreach ($a as $level => [$key, $id]) {
            if (! isset($b[$level])) {
                return 1;
            }

            [$otherKey, $otherId] = $b[$level];
            $order = [$key[0], $key[1], $key[2], $id] <=> [$otherKey[0], $otherKey[1], $otherKey[2], $otherId];

            if ($order !== 0) {
                return $order;
            }
        }

        return count($a) <=> count($b);
    }
}
