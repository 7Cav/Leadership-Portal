<?php

namespace App\Integration;

/**
 * One game server a telemetry API lists (#389): its id at that API and the name it goes by there. Every seam's
 * `listServers()` returns these, so the fetch treats CRCON, BattleMetrics and Nightwatch alike. `gameSlug` is the
 * portal game the API says the server runs, when it says; it only fills in the game of a newly listed server.
 */
final readonly class ListedServer
{
    public function __construct(
        public string $externalId,
        public string $name,
        public ?string $gameSlug = null,
    ) {}
}
