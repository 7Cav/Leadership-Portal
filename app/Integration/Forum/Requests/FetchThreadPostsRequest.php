<?php

namespace App\Integration\Forum\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Lists one thread's posts, a page at a time, oldest first. `GET {base}/api/threads/{id}/posts`, read with the forum
 * API key (applied by ForumApi). Each post carries its author (`user_id`, `username`), its raw BBCode and its state.
 */
class FetchThreadPostsRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $threadId,
        private readonly int $page = 1,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/threads/{$this->threadId}/posts";
    }

    /**
     * @return array<string, int>
     */
    protected function defaultQuery(): array
    {
        return ['page' => $this->page];
    }
}
