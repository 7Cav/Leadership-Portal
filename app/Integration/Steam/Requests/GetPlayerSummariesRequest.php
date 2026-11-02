<?php

namespace App\Integration\Steam\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Reads the public profile of one Steam account: `GET {base}/ISteamUser/GetPlayerSummaries/v2/?steamids={id}`. The only
 * request class in `Integration/Steam`; nothing that changes anything on Steam is modelled.
 */
class GetPlayerSummariesRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $steamId) {}

    public function resolveEndpoint(): string
    {
        return '/ISteamUser/GetPlayerSummaries/v2/';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return ['steamids' => $this->steamId];
    }
}
