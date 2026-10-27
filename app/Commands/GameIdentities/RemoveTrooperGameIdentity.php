<?php

namespace App\Commands\GameIdentities;

use App\Models\Game;

/**
 * Remove a trooper's identity in one game. Handled by {@see RemoveTrooperGameIdentityHandler}.
 */
final readonly class RemoveTrooperGameIdentity
{
    public function __construct(
        public int $trooperId,
        public Game $game,
    ) {}
}
