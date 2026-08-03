<?php

namespace App\Integration\Cav\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Fetches one trooper's full milpac profile.
 * `GET {base}/api/v1/milpacs/profile/id/{cavId}`.
 *
 * The path value is the **milpac relation id**, not the forum user id — the API's own
 * spec says so, and it is confirmed live (`id/1` returns the trooper whose body reports
 * `user.userId: 3`). Passing a forum id here silently returns a different trooper, which
 * is why the caller re-checks the returned forum user id.
 */
class FetchMilpacProfileRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly int $cavId) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/milpacs/profile/id/{$this->cavId}";
    }
}
