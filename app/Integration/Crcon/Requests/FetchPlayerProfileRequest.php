<?php

namespace App\Integration\Crcon\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Reads one player's profile, for its session list: `GET {base}/api/get_player_profile?player_id=<id>`.
 *
 * CRCON's history endpoint returns an empty `sessions` list, so the seam asks each candidate's
 * profile for the real sessions. Read-only, like the other request classes here.
 */
class FetchPlayerProfileRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $playerId) {}

    public function resolveEndpoint(): string
    {
        return '/api/get_player_profile';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return ['player_id' => $this->playerId];
    }
}
