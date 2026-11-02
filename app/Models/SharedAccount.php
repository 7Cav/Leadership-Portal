<?php

namespace App\Models;

/**
 * The accounts one player ID is shared across games by (#174): a Steam ID is the same in every Steam game, and an
 * Epic (EOS) ID the same in every Epic game. A game's ID kind is matched here by its name, case and spaces ignored, so
 * "Steam ID" on one game and "SteamID64" on another are one account. Any other kind (a DCS UCID, an Arma ID) is
 * entered per game.
 */
enum SharedAccount: string
{
    case Steam = 'steam';
    case Epic = 'epic';

    public static function of(string $kind): ?self
    {
        return match (strtolower(preg_replace('/\s+/', '', $kind) ?? '')) {
            'steamid', 'steamid64', 'steam' => self::Steam,
            'epicid', 'eosid', 'epicaccountid', 'epic', 'eos' => self::Epic,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Steam => 'Steam',
            self::Epic => 'Epic',
        };
    }

    /** The kind `$game` asks for this account as, its first that matches, or null when it asks for none. */
    public function kindOn(Game $game): ?string
    {
        foreach ($game->id_kinds as $kind) {
            if (self::of($kind) === $this) {
                return $kind;
            }
        }

        return null;
    }
}
