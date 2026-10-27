<?php

namespace App\Commands\Servers;

use RuntimeException;

/** A game server cannot be set up as asked (#389). The message is shown to the Admin. */
class InvalidGameServerException extends RuntimeException
{
    public static function selectedWithoutGame(): self
    {
        return new self('Pick the game this server is for before selecting it.');
    }

    public static function retiredGame(string $game): self
    {
        return new self("{$game} is retired, so no server can be newly assigned to it.");
    }

    public static function fallbackNotAllowed(): self
    {
        return new self('Only a CRCON server can have a BattleMetrics fallback.');
    }

    public static function stillAFallback(string $server, string $user): self
    {
        return new self("{$server} is the fallback of {$user}. Remove it there before deselecting it.");
    }

    public static function fallbackNotBattleMetrics(): self
    {
        return new self('The fallback must be a selected BattleMetrics server.');
    }
}
