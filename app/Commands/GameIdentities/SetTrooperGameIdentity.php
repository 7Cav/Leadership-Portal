<?php

namespace App\Commands\GameIdentities;

use App\Models\Game;
use App\Models\GameIdentityText;
use App\Models\Gamertag;
use App\Models\IdentitySource;
use App\Repositories\TrooperGameIdentityRepository;

/**
 * Link a trooper to a player ID in one game, or correct the identity they already have there. `$idKind` is which of the game's ID kinds (#428) the id was entered as; null
 * keeps the kind the trooper's identity already has for that same id, or is the game's default. Handled by {@see SetTrooperGameIdentityHandler}.
 *
 * `$replacing` makes the write conditional (#176): when set, it goes ahead only if the trooper's
 * identity in that game, read under lock in the write's own transaction, is none, already
 * `$platformId`, or exactly `$replacing` — the platform id the leader was shown when they chose to
 * replace it. Anything else means it changed since they were asked, and nothing is written. Null
 * (the Game identities screen, where the leader edits the row they see) writes unconditionally.
 *
 * The ID is cleaned of hidden characters and normalised here ({@see GameIdentityText}, #450); the handler refuses one
 * left empty, and checks the ID against its kind's shape unless `$reportedByServer`: an ID a game
 * server reported (linking an unmatched player) is the one the capture matches, whatever shape it has.
 *
 * `$source` is how a new row came to be (#174): typed here, from_rollcall when linking an unmatched player. A write that
 * keeps the same ID keeps the row's source; a new ID takes the command's.
 *
 * `$gamertag` sets the player's in-game name shown beside the ID (its value null clears it); null leaves it as the
 * repository does ({@see TrooperGameIdentityRepository::link()}): kept for the same ID, gone with a new one.
 */
final readonly class SetTrooperGameIdentity
{
    public string $platformId;

    public ?string $replacing;

    public ?string $idKind;

    public function __construct(
        public int $trooperId,
        public Game $game,
        string $platformId,
        ?string $replacing = null,
        ?string $idKind = null,
        public bool $reportedByServer = false,
        public IdentitySource $source = IdentitySource::Typed,
        public ?Gamertag $gamertag = null,
    ) {
        $this->platformId = GameIdentityText::clean($platformId);
        $this->replacing = $replacing === null ? null : trim($replacing);
        $this->idKind = $idKind === null ? null : trim($idKind);
    }
}
