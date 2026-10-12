<?php

namespace App\Models;

/**
 * The game a trooper's in-game identity belongs to (AD-19). The values mirror the
 * `trooper_game_identities.platform` DB enum by hand — change both together.
 */
enum GamePlatform: string
{
    case Hll = 'hll';
    case Arma = 'arma';

    /** How the game is named on screen. */
    public function label(): string
    {
        return match ($this) {
            self::Hll => 'Hell Let Loose',
            self::Arma => 'Arma Reforger',
        };
    }

    /** What the identifier the capture resolver matches on is called to a leader (AD-19). */
    public function idLabel(): string
    {
        return match ($this) {
            self::Hll => 'Steam ID',
            self::Arma => 'Player ID',
        };
    }
}
