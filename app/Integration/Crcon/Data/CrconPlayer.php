<?php

namespace App\Integration\Crcon\Data;

/**
 * One player observed on the server: the in-game name plus the stable platform id (Steam id or
 * PlayFab id) that identity linking (7.2) keys on. The name can change; the id cannot.
 */
readonly class CrconPlayer
{
    public function __construct(
        public string $playerName,
        public string $platformId,
    ) {}
}
