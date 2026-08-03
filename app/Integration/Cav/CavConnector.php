<?php

namespace App\Integration\Cav;

use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Saloon connector for api.7cav.us, the Cav roster/milpac API. The concrete outbound
 * edge (AD-3) — the only place an HTTP client touches the roster. Reached only from
 * {@see CavApi}, which is called from CommandHandlers; Deptrac forbids any other path out.
 *
 * The static `cav7_…` bearer key is applied here as the connector's default auth, so no
 * request can be sent unauthenticated by omission. It grants read-only access and carries
 * no user context, which is what makes AD-5's "never written back to Cav" structural
 * rather than a convention.
 *
 * Bounded timeouts: enrichment runs on the synchronous login path, so a slow or hung
 * roster API must fail fast rather than pin a PHP-FPM worker for Guzzle's long default.
 */
class CavConnector extends Connector
{
    use AcceptsJson, HasTimeout;

    protected int $connectTimeout = 5;

    protected int $requestTimeout = 10;

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
