<?php

namespace App\Commands\GameIdentities;

use App\Models\Game;
use DateTimeImmutable;

/**
 * A leader resolves one unmatched player of a captured practice to the trooper they chose
 * (Story 7.7, AD-19): link the identity, then pre-mark that trooper present. `$week` is any moment
 * in the practice's Cav week (AD-11). Handled by {@see LinkUnmatchedPlayerHandler}.
 *
 * `$replace` is the leader's answer to "replace or cancel" (#176): true only once they have seen the
 * different identity the trooper already holds on that game and chose to replace it. Without it a
 * held identity is never overwritten.
 *
 * `$replacing` is the platform id of the identity they were shown. A replace only ever overwrites
 * that one: if the trooper's identity changed since the question (another leader, the Game
 * identities screen), the handler refuses and asks again with the new one. `$replace` without
 * `$replacing` replaces nothing — it is refused like a link without `$replace`, never treated as
 * "overwrite whatever is there".
 */
final readonly class LinkUnmatchedPlayer
{
    public function __construct(
        public int $sectionId,
        public DateTimeImmutable $week,
        public Game $game,
        public string $platformId,
        public int $trooperId,
        public bool $replace = false,
        public ?string $replacing = null,
    ) {}
}
