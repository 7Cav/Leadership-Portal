<?php

namespace App\Integration\Crcon\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Reads the public facts about the server a CRCON instance runs, for its name (#389): `GET {base}/api/get_public_info`.
 * A read, like the player history; nothing that administers the server is modelled.
 */
class FetchPublicInfoRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/get_public_info';
    }
}
