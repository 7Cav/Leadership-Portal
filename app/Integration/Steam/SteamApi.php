<?php

namespace App\Integration\Steam;

use App\Integration\Steam\Requests\GetPlayerSummariesRequest;
use Throwable;

/**
 * The single door out to Steam's Web API (AD-3): whether a Steam ID (SteamID64) belongs to an account, asked when a
 * leader links one (#174). A CommandHandler calls this; the key reaches it only via injected config (AD-23).
 *
 * Every failure leaves through {@see SteamUnavailable}, so a caller can tell "Steam has no such account" (false) from
 * "Steam could not be asked". The key is never put in an exception message or a log.
 */
class SteamApi
{
    /**
     * @param  array{base_url?: string|null, web_api_key?: string|null}|null  $config
     */
    public function __construct(private readonly ?array $config = []) {}

    /** Whether a key is set, so the check can run at all. */
    public function configured(): bool
    {
        $key = $this->config['web_api_key'] ?? null;

        return is_string($key) && trim($key) !== '';
    }

    /**
     * Whether Steam knows an account with this ID.
     *
     * @throws SteamUnavailable when Steam cannot be asked or its answer cannot be read.
     */
    public function accountExists(string $steamId): bool
    {
        if (! $this->configured()) {
            throw SteamUnavailable::misconfigured('STEAM_WEB_API_KEY is empty');
        }

        $baseUrl = $this->config['base_url'] ?? null;

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            throw SteamUnavailable::misconfigured('the base URL is empty');
        }

        try {
            $response = (new SteamConnector(trim($baseUrl), trim((string) $this->config['web_api_key'])))
                ->send(new GetPlayerSummariesRequest($steamId));
        } catch (Throwable) {
            // Dropped on purpose: a transport error's message can carry the URL, and with it the key.
            throw SteamUnavailable::unreachable();
        }

        if ($response->failed()) {
            throw SteamUnavailable::httpError($response->status());
        }

        try {
            $players = $response->json('response.players');
        } catch (Throwable) {
            throw SteamUnavailable::malformed('the body is not JSON');
        }

        if (! is_array($players)) {
            throw SteamUnavailable::malformed('there is no player list');
        }

        foreach ($players as $player) {
            if (is_array($player) && (string) ($player['steamid'] ?? '') === $steamId) {
                return true;
            }
        }

        return false;
    }
}
