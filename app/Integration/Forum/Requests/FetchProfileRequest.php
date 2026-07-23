<?php

namespace App\Integration\Forum\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Fetches the authenticated forum user's profile.
 * `GET {base}/api/me` (XenForo 2.3), authenticated with the OAuth access token
 * as a Bearer credential (applied by ForumApi — no FQCN @see here on purpose, so
 * Pint can't promote it to a same-file-redundant import).
 * Returns a `me` object with the forum `user_id` and `username`.
 */
class FetchProfileRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/me';
    }
}
