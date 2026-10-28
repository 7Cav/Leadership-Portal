<?php

namespace App\Commands\GameIdentities;

use App\Models\Game;
use App\Models\GameIdentityText;

/**
 * Link a trooper to a player in one game, or correct the identity they already have there
 * (a rename keeps the same row). `$idKind` is which of the game's ID kinds (#428) the id was entered as; null
 * keeps the kind the trooper's identity already has for that same id, or is the game's default. Handled by {@see SetTrooperGameIdentityHandler}.
 *
 * `$replacing` makes the write conditional (#176): when set, it goes ahead only if the trooper's
 * identity in that game, read under lock in the write's own transaction, is none, already
 * `$platformId`, or exactly `$replacing` — the platform id the leader was shown when they chose to
 * replace it. Anything else means it changed since they were asked, and nothing is written. Null
 * (the Game identities screen, where the leader edits the row they see) writes unconditionally.
 *
 * The player name and ID are cleaned of hidden characters and normalised here ({@see GameIdentityText}, #450); the
 * handler refuses one left empty, and checks the ID against its kind's shape unless `$reportedByServer`: an ID a game
 * server reported (linking an unmatched player) is the one the capture matches, whatever shape it has.
 */
final readonly class SetTrooperGameIdentity
{
    public string $playerName;

    public string $platformId;

    public ?string $replacing;

    public ?string $idKind;

    public function __construct(
        public int $trooperId,
        public Game $game,
        string $playerName,
        string $platformId,
        ?string $replacing = null,
        ?string $idKind = null,
        public bool $reportedByServer = false,
    ) {
        $this->playerName = GameIdentityText::clean($playerName);
        $this->platformId = GameIdentityText::clean($platformId);
        $this->replacing = $replacing === null ? null : trim($replacing);
        $this->idKind = $idKind === null ? null : trim($idKind);
    }
}
