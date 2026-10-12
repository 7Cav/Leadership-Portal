<?php

namespace App\Integration\BattleMetrics;

use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Saloon connector for the BattleMetrics API. The concrete outbound edge (AD-3) — reached only
 * from {@see BattleMetricsApi}.
 *
 * The API key is the connector's default bearer auth, so no request can go out unauthenticated
 * by omission. Bounded timeouts so a slow upstream fails promptly rather than holding a worker.
 */
class BattleMetricsConnector extends Connector
{
    use AcceptsJson, HasTimeout;

    protected int $connectTimeout = 5;

    protected int $requestTimeout = 15;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
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
