<?php

namespace App\Commands\GameIdentities;

use App\Commands\Capture\CaptureSources;
use App\Integration\Crcon\CrconApi;
use App\Integration\Crcon\CrconUnavailable;
use App\Models\Game;
use App\Models\TelemetryInterface;
use App\Repositories\GameServerRepository;
use Illuminate\Support\Facades\Cache;

/**
 * "Fill in from game server": which platform a player ID belongs to and the player's current in-game name, read from
 * the game's CRCON servers with `get_player_profile`. Read-only; nothing on a server changes.
 *
 * Only a game read through CRCON can be asked. Its selected CRCON servers are asked in order and the first that knows
 * the player answers; a game with none selected asks the CRCON env server when that server is its own
 * ({@see CaptureSources::LEGACY_GAMES}). CRCON's platform maps to a kind ({@see self::PLATFORM_KINDS}) only when the
 * game asks for that kind.
 *
 * An answer (found or not seen) is kept for {@see self::CACHE_MINUTES} minutes, so an import's save judges each line
 * as its preview did without asking again. An outage is never kept. The name is for display only: a gamertag can change
 * and identities are always matched by ID.
 */
class PlatformLookup
{
    /** CRCON's platform names and the ID kind each is. */
    public const PLATFORM_KINDS = [
        'steam' => 'Steam ID',
        'epic' => 'Epic ID',
        'xbl' => 'Xbox ID',
        'psn' => 'PlayStation ID',
    ];

    public const CACHE_MINUTES = 10;

    /** What a looked-up ID looks like: 32 hex characters (an Xbox, PlayStation, Epic or EOS ID), any case. */
    public const LOOKUP_SHAPE = '/^[0-9a-fA-F]{32}$/';

    public function __construct(
        private readonly CrconApi $crcon,
        private readonly GameServerRepository $servers,
    ) {}

    /** Whether the game's servers can be asked at all: it is read through CRCON. */
    public function applies(Game $game): bool
    {
        return $game->interface === TelemetryInterface::Crcon;
    }

    /** The answer kept from an earlier lookup of this ID on this game, or null. */
    public function cached(Game $game, string $playerId): ?PlatformLookupResult
    {
        $data = Cache::get($this->key($game, $playerId));

        return is_array($data) ? PlatformLookupResult::fromArray($data) : null;
    }

    /**
     * Ask the game's servers about the player. `$transportFailed` is set when a server could not be reached, so a
     * caller looking up many IDs can stop asking.
     */
    public function lookup(Game $game, string $playerId, bool &$transportFailed = false): PlatformLookupResult
    {
        $playerId = trim($playerId);

        if (! $this->applies($game) || $playerId === '') {
            return PlatformLookupResult::notChecked();
        }

        if (($kept = $this->cached($game, $playerId)) !== null) {
            return $kept;
        }

        $serverIds = $this->servers->selectedFor($game, TelemetryInterface::Crcon)->pluck('external_id')->all();

        if ($serverIds === [] && ! $this->servers->hasSelectedFor($game) && (CaptureSources::LEGACY_GAMES['crcon'] ?? null) === $game->slug) {
            $serverIds = [null];
        }

        $answered = false;
        $failed = false;

        foreach ($serverIds as $serverId) {
            try {
                $profile = $this->crcon->playerProfile($playerId, $serverId);
            } catch (CrconUnavailable) {
                $failed = true;
                $transportFailed = true;

                continue;
            }

            $answered = true;

            if ($profile !== null) {
                $kind = self::PLATFORM_KINDS[$profile->platform ?? ''] ?? null;
                $result = PlatformLookupResult::found($kind !== null && $game->hasIdKind($kind) ? $kind : null, $profile->platform, $profile->name);

                return $this->keep($game, $playerId, $result);
            }
        }

        // A server that could not be asked may be the one that knows the player.
        if (! $answered || $failed) {
            return PlatformLookupResult::notChecked();
        }

        return $this->keep($game, $playerId, PlatformLookupResult::notSeen());
    }

    private function keep(Game $game, string $playerId, PlatformLookupResult $result): PlatformLookupResult
    {
        Cache::put($this->key($game, $playerId), $result->toArray(), now()->addMinutes(self::CACHE_MINUTES));

        return $result;
    }

    private function key(Game $game, string $playerId): string
    {
        return 'platform-lookup:'.$game->id.':'.strtolower($playerId);
    }
}
