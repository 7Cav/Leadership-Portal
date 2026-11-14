<?php

namespace App\Milpac;

use App\Models\Trooper;
use App\Models\TrooperPlacement;
use App\Models\Unit;
use App\Repositories\TrooperPlacementRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * A trooper's moves between units (decided 2026-10-27), for the milpac and the roster Sheet: "moved from 2/1/A/1-7 to
 * 1/2/A/1-7" with the date. Read from the placement history the roster sync and the Admin's placement write. Newest
 * first. A placement the history has from before it began (no start date) is not a move and is not listed.
 *
 * A move straight back where the trooper came from is most likely someone correcting a wrong milpac edit (a trooper
 * moved by mistake, then put back). The milpac leaves out one undone inside the same Cav week, since as far as the week
 * is concerned it never happened, and marks one undone in a later week. The Admin's list of changes shows both kinds,
 * marked, since catching the wrong edit is what it is for.
 */
final class PlacementHistory
{
    public function __construct(
        private readonly TrooperPlacementRepository $placements,
    ) {}

    /**
     * `from` is null for a first placement and `to` when they were left unplaced. `reversedAt` is when a move was
     * undone in a later week, and `reverses` marks the move that undid it.
     *
     * @return list<array{at: string, from: ?string, to: ?string, reversedAt: ?string, reverses: bool}>
     */
    public function transfersOf(int $trooperId): array
    {
        $moves = self::markRoundTrips(self::movesIn($this->placements->historyOf($trooperId)->values()), dropWithinWeek: true);
        usort($moves, fn (array $a, array $b) => $b['at'] <=> $a['at']);

        return array_map(fn (array $move) => [
            'at' => $move['at']->toIso8601ZuluString(),
            'from' => $move['from'],
            'to' => $move['to'],
            'reversedAt' => $move['reversedAt']?->toIso8601ZuluString(),
            'reverses' => $move['reverses'],
        ], $moves);
    }

    /**
     * Every move made at or after `$since`, newest first, for the Admin's list of placement changes: who moved, from
     * where to where, whether the roster sync or an Admin did it, and whether it was undone (`reversedAt`) or undid one
     * (`reverses`), however soon. Moves written by one sync run share their time.
     *
     * @return list<array{at: string, trooperId: int, trooper: string, from: ?string, to: ?string, source: ?string, reversedAt: ?string, reverses: bool}>
     */
    public function changesSince(CarbonInterface $since): array
    {
        $since = CarbonImmutable::instance($since)->utc();
        $changes = [];

        foreach ($this->placements->historiesChangedSince($since) as $trooperId => $rows) {
            $trooper = $rows->first()?->trooper;

            foreach (self::markRoundTrips(self::movesIn($rows), dropWithinWeek: false) as $move) {
                if ($move['at']->lt($since)) {
                    continue;
                }

                $changes[] = [
                    'at' => $move['at'],
                    'trooperId' => (int) $trooperId,
                    'trooper' => $trooper instanceof Trooper ? trim(($trooper->rank_short ?? '').' '.($trooper->username ?? '#'.$trooper->cav_id)) : '#'.$trooperId,
                    'from' => $move['from'],
                    'to' => $move['to'],
                    'source' => $move['source'],
                    'reversedAt' => $move['reversedAt']?->toIso8601ZuluString(),
                    'reverses' => $move['reverses'],
                ];
            }
        }

        usort($changes, fn (array $a, array $b) => $b['at'] <=> $a['at'] ?: strcmp($a['trooper'], $b['trooper']));

        return array_map(fn (array $change) => [...$change, 'at' => $change['at']->toIso8601ZuluString()], $changes);
    }

    /**
     * The moves in one trooper's history, oldest first, each with who made it (the source of the row it opened, or of
     * the row it closed for a move to nowhere).
     *
     * @param  Collection<int, TrooperPlacement>  $rows  oldest first
     * @return list<array{at: CarbonImmutable, from: ?string, to: ?string, source: ?string}>
     */
    private static function movesIn(Collection $rows): array
    {
        $rows = $rows->values();
        $moves = [];

        foreach ($rows as $index => $row) {
            $previous = $index > 0 ? $rows[$index - 1] : null;

            if ($row->placed_from !== null) {
                $joined = $previous?->placed_until !== null && $previous->placed_until->equalTo($row->placed_from);
                $moves[] = ['at' => $row->placed_from, 'from' => $joined ? self::label($previous) : null, 'to' => self::label($row), 'source' => $row->source?->value];
            }

            $next = $rows[$index + 1] ?? null;

            // Left a unit for no other: unplaced (their unit removed, say), shown as a move to nowhere.
            if ($row->placed_until !== null && ($next === null || $next->placed_from === null || ! $next->placed_from->equalTo($row->placed_until))) {
                $moves[] = ['at' => $row->placed_until, 'from' => self::label($row), 'to' => null, 'source' => $row->source?->value];
            }
        }

        usort($moves, fn (array $a, array $b) => $a['at'] <=> $b['at']);

        return $moves;
    }

    /**
     * Marks each move undone by the very next one (straight back where the trooper came from): `reversedAt` on the
     * first, `reverses` on the second. With `$dropWithinWeek`, a pair inside one Cav week is left out instead.
     *
     * @param  list<array{at: CarbonImmutable, from: ?string, to: ?string, source: ?string}>  $moves  oldest first
     * @return list<array{at: CarbonImmutable, from: ?string, to: ?string, source: ?string, reversedAt: ?CarbonImmutable, reverses: bool}>
     */
    private static function markRoundTrips(array $moves, bool $dropWithinWeek): array
    {
        $kept = [];

        for ($i = 0; $i < count($moves); $i++) {
            $move = $moves[$i];
            $back = $moves[$i + 1] ?? null;
            $returns = $back !== null && $move['from'] !== null && $move['to'] !== null
                && $back['from'] === $move['to'] && $back['to'] === $move['from'];

            if ($returns && $dropWithinWeek && WeekOf::start($move['at'])->equalTo(WeekOf::start($back['at']))) {
                $i++;

                continue;
            }

            $kept[] = [...$move, 'reversedAt' => $returns ? $back['at'] : null, 'reverses' => false];

            if ($returns) {
                $kept[] = [...$back, 'reversedAt' => null, 'reverses' => true];
                $i++;
            }
        }

        return $kept;
    }

    private static function label(TrooperPlacement $row): string
    {
        $unit = $row->unit;

        return $unit instanceof Unit ? ($unit->callsign ?? $unit->name) : 'a unit since removed';
    }
}
