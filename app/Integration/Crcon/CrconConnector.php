<?php

namespace App\Integration\Crcon;

use App\Integration\RefusesRedirects;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Saloon connector for a unit's CRCON (the community RCON tool for Hell Let Loose). The concrete
 * outbound edge (AD-3) — reached only from {@see CrconApi}.
 *
 * The API key is the connector's default auth, so no request can go out unauthenticated by
 * omission. Bounded timeouts: capture runs in queued work, but a hung game server must still
 * fail promptly rather than hold a worker.
 */
class CrconConnector extends Connector
{
    use AcceptsJson, HasTimeout, RefusesRedirects;

    protected int $connectTimeout = 5;

    protected int $requestTimeout = 15;

    public function __construct(
        private readonly string $baseUrl,
        #[\SensitiveParameter] private readonly string $apiKey,
    ) {}

    public function resolveBaseUrl(): string
    {
        return $this->baseUrl;
    }

    protected function defaultAuth(): TokenAuthenticator
    {
        return new TokenAuthenticator($this->apiKey);
    }
}
