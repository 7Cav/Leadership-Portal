<?php

namespace App\Milpac;

use App\Models\TrooperPlacement;
use App\Models\Unit;
use App\Repositories\TrooperPlacementRepository;

/**
 * A trooper's moves between units (decided 2026-10-27), for the milpac and the roster Sheet: "moved from 2/1/A/1-7 to
 * 1/2/A/1-7" with the date. Read from the placement history the roster sync and the Admin's placement write. Newest
 * first. A placement the history has from before it began (no start date) is not a move and is not listed.
 */
final class PlacementHistory
{
    public function __construct(
        private readonly TrooperPlacementRepository $placements,
    ) {}

    /**
     * @return list<array{at: string, from: ?string, to: ?string}> `from` null for a first placement, `to` null when
     *                                                             they were left unplaced
     */
    public function transfersOf(int $trooperId): array
    {
        $rows = $this->placements->historyOf($trooperId)->values();
        $moves = [];

        foreach ($rows as $index => $row) {
            $previous = $index > 0 ? $rows[$index - 1] : null;

            if ($row->placed_from !== null) {
                $joined = $previous?->placed_until !== null && $previous->placed_until->equalTo($row->placed_from);
                $moves[] = ['at' => $row->placed_from, 'from' => $joined ? $this->label($previous) : null, 'to' => $this->label($row)];
            }

            $next = $rows[$index + 1] ?? null;

            // Left a unit for no other: unplaced (their unit removed, say), shown as a move to nowhere.
            if ($row->placed_until !== null && ($next === null || $next->placed_from === null || ! $next->placed_from->equalTo($row->placed_until))) {
                $moves[] = ['at' => $row->placed_until, 'from' => $this->label($row), 'to' => null];
            }
        }

        usort($moves, fn (array $a, array $b) => $b['at'] <=> $a['at']);

        return array_map(fn (array $move) => [...$move, 'at' => $move['at']->toIso8601ZuluString()], $moves);
    }

    private function label(TrooperPlacement $row): string
    {
        $unit = $row->unit;

        return $unit instanceof Unit ? ($unit->callsign ?? $unit->name) : 'a unit since removed';
    }
}
