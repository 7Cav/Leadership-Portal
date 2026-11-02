<?php

namespace App\Integration\Crcon\Data;

/**
 * What a CRCON server knows about one player from `get_player_profile`: the platform they play on as CRCON names it
 * (`steam`, `epic`, `xbl`, `psn`, or something new), lower case, and their current in-game name. Either may be
 * missing when the profile does not carry it. Read-only; nothing here is matched on.
 */
readonly class CrconPlayerProfile
{
    public function __construct(
        public string $playerId,
        public ?string $platform,
        public ?string $name,
    ) {}
}
