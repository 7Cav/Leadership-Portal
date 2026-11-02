<?php

namespace App\Commands\GameIdentities;

use App\Models\Game;

/**
 * Remove a trooper's Steam or Epic ID from every game it is linked on (#174), found from their identity on `$game`. Handled by
 * {@see RemoveSharedAccountHandler}.
 */
final readonly class RemoveSharedAccount
{
    public function __construct(
        public int $trooperId,
        public Game $game,
    ) {}
}
