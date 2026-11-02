<?php

namespace App\Commands\GameIdentities;

use App\Models\Game;

/**
 * Remove every row a trooper's Steam or Epic account made (#174), found from their identity on `$game`. Handled by
 * {@see RemoveSharedAccountHandler}.
 */
final readonly class RemoveSharedAccount
{
    public function __construct(
        public int $trooperId,
        public Game $game,
    ) {}
}
