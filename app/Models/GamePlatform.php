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
}
