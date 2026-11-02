<?php

namespace App\Integration\Steam;

use App\Integration\RefusesRedirects;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Saloon connector for Steam's Web API. The concrete outbound edge (AD-3), reached only from {@see SteamApi}.
 *
 * Steam takes the key as a `key` query parameter, so it is added here as a default and no request goes out without it.
 * Short timeouts: the check runs while a leader waits on a save, and a slow Steam only means the save goes ahead unchecked.
 */
class SteamConnector extends Connector
{
    use AcceptsJson, HasTimeout, RefusesRedirects;

    protected int $connectTimeout = 2;

    protected int $requestTimeout = 4;

    public function __construct(
        private readonly string $baseUrl,
        #[\SensitiveParameter] private readonly string $apiKey,
    ) {}

    public function resolveBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return ['key' => $this->apiKey];
    }
}
