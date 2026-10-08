<?php

namespace App\Integration\Cav\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Fetches every milpac position group with its positions (story 2.9).
 * `GET {base}/api/v1/milpacs/position/groups`.
 *
 * The one place the Cav API publishes the regiment's structure: each company group (`A/1-7`)
 * lists its billets, and the billet titles encode the full chain (`Trooper 2/1/A/1-7`).
 */
class FetchPositionGroupsRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/milpacs/position/groups';
    }
}
