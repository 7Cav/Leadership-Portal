<?php

namespace App\Integration\Nightwatch\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Lists the workspace's servers with their current state, for their ids and names (#389): `GET {base}/servers/live`.
 */
class FetchLiveServersRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/servers/live';
    }
}
