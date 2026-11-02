<?php

namespace App\Commands\GameIdentities;

use App\Models\Game;
use App\Models\GameIdentityText;

/**
 * Link a trooper's Steam or Epic account to every active game that asks for it (#174), from the Game identities form
 * on `$game` with the ID entered as `$idKind`. Handled by {@see LinkSharedAccountHandler}.
 */
final readonly class LinkSharedAccount
{
    public string $playerName;

    public string $platformId;

    public function __construct(
        public int $trooperId,
        public Game $game,
        public string $idKind,
        string $playerName,
        string $platformId,
    ) {
        $this->playerName = GameIdentityText::clean($playerName);
        $this->platformId = GameIdentityText::clean($platformId);
    }
}
