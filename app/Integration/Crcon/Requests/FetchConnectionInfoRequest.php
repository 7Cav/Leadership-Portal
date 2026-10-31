<?php

namespace App\Integration\Crcon\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Who a CRCON instance is: its server's `name`, `game` (`hll` or `hllv`) and `server_number`, the number its
 * log lines are stored under. Read-only.
 */
class FetchConnectionInfoRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/get_connection_info';
    }
}
