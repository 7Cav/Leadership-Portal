<?php

namespace App\Integration\Forum;

use Saloon\Http\Connector;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Saloon connector for the 7cav.us forum (XenForo 2.3). This is the concrete
 * outbound edge (AD-3) — the only place an HTTP client touches the forum. Reached
 * only from {@see ForumApi}, which is called from CommandHandlers; Deptrac forbids
 * any other path out.
 *
 * Bounded timeouts: the token/profile calls sit on a synchronous, user-facing login
 * path, so a slow or hung forum must fail fast (→ AC #5's "back to login") rather
 * than pinning a PHP-FPM worker for Guzzle's long default.
 */
class ForumConnector extends Connector
{
    use HasTimeout;

    protected int $connectTimeout = 5;

    protected int $requestTimeout = 10;

    public function __construct(private readonly string $baseUrl) {}

    public function resolveBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
