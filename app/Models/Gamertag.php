<?php

namespace App\Models;

/**
 * A gamertag to store on an identity: the player's current in-game name, cleaned of hidden characters and held to
 * {@see self::MAX} characters, and whether a game server gave it. A null value clears it. Display only; never matched on.
 */
final readonly class Gamertag
{
    public const MAX = 100;

    public ?string $value;

    public function __construct(?string $value, public bool $fromServer = false)
    {
        $value = $value === null ? '' : mb_substr(GameIdentityText::clean($value), 0, self::MAX);
        $this->value = $value === '' ? null : $value;
    }
}
