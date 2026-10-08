<?php

namespace App\Integration\Forum\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Fetches one thread with its first post. `GET {base}/api/threads/{id}`, read with the forum API key
 * (applied by ForumApi).
 */
class FetchThreadRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly int $threadId) {}

    public function resolveEndpoint(): string
    {
        return "/api/threads/{$this->threadId}";
    }
}
