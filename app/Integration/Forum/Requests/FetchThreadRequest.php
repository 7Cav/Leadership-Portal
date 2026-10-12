<?php

namespace App\Integration\Forum\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Fetches one thread with its first post. `GET {base}/api/threads/{id}`, read with the forum API key
 * (applied by ForumApi). The forum leaves the first post out unless asked, so `with_first_post` is sent:
 * it is where a leave request's fields live.
 */
class FetchThreadRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly int $threadId) {}

    public function resolveEndpoint(): string
    {
        return "/api/threads/{$this->threadId}";
    }

    /**
     * @return array<string, int>
     */
    protected function defaultQuery(): array
    {
        return ['with_first_post' => 1];
    }
}
