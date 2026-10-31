<?php

namespace App\Integration\Crcon\Data;

/**
 * One player observed on the server: the in-game name plus the stable platform id (Steam id or
 * PlayFab id) that identity linking (7.2) keys on. The name can change; the id cannot.
 *
 * `idSource` is set when `platformId` is not the player's game ID but a service's own number for them:
 * {@see self::BATTLEMETRICS_PLAYER} when BattleMetrics knew no game ID for the player and gave its player number
 * instead. Null for every game ID.
 *
 * `minutes` is how long the player was on the server inside the window read (#558), their stays clipped to it and
 * summed, so a reconnect counts once. Null when the source only knows the player was seen (BattleMetrics, Nightwatch, or
 * CRCON's first/last-seen fallback): such a player is never given an automatic make-up.
 */
readonly class CrconPlayer
{
    /** A BattleMetrics player number, not a game ID. */
    public const BATTLEMETRICS_PLAYER = 'battlemetrics';

    public function __construct(
        public string $playerName,
        public string $platformId,
        public ?string $idSource = null,
        public ?int $minutes = null,
    ) {}

    /** The same player under another ID spelling or marker, keeping the minutes. */
    public function with(?string $platformId = null, ?string $idSource = null, bool $clearIdSource = false): self
    {
        return new self($this->playerName, $platformId ?? $this->platformId, $clearIdSource ? null : ($idSource ?? $this->idSource), $this->minutes);
    }
}
