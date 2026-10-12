<?php

namespace App\Integration\Nightwatch\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Reads the recent matches (last 48 hours) on one server, each with its full scoreboard:
 * `GET {base}/matches?limit=N&server=<id>`.
 *
 * The only request class in `Integration/Nightwatch`. The community quest POST endpoints and
 * anything else that writes are deliberately not modelled. (`GET /servers/live` also exists, for
 * a future short poll; it is not needed here.)
 */
class FetchMatchesRequest extends Request
{
    public const LIMIT = 100;

    protected Method $method = Method::GET;

    public function __construct(private readonly string $serverId) {}

    public function resolveEndpoint(): string
    {
        return '/matches';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return ['limit' => self::LIMIT, 'server' => $this->serverId];
    }
}
