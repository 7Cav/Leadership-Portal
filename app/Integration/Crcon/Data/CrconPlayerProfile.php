<?php

namespace App\Integration\Crcon\Data;

/**
 * What a CRCON server knows about one player from `get_player_profile`: the platform they play on as CRCON names it
 * (`steam`, `epic`, `xbl`, `psn`, or something new), lower case, and their current in-game name. Either may be
 * missing when the profile does not carry it. `nativeId` is the player's ID on their own platform, from the profile's
 * `steam_id`, which CRCON fills for every platform (a Steam ID for Steam, the console's own ID on PlayStation or Xbox):
 * capture matches a player its log names by an EOS ID that nobody linked on it (15NOV26). Read-only.
 */
readonly class CrconPlayerProfile
{
    public function __construct(
        public string $playerId,
        public ?string $platform,
        public ?string $name,
        public ?string $nativeId = null,
    ) {}
}
