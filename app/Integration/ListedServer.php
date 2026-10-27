<?php

namespace App\Integration;

/**
 * One game server a telemetry API lists (#389): its id at that API and the name it goes by there. Every seam's
 * `listServers()` returns these, so the fetch treats CRCON, BattleMetrics and Nightwatch alike.
 */
final readonly class ListedServer
{
    public function __construct(
        public string $externalId,
        public string $name,
    ) {}
}
