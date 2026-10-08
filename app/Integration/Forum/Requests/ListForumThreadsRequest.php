<?php

namespace App\Integration\Forum\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Lists one forum section's threads, a page at a time. `GET {base}/api/forums/{node}/threads`, read
 * with the forum API key (applied by ForumApi).
 */
class ListForumThreadsRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $nodeId,
        private readonly int $page = 1,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/forums/{$this->nodeId}/threads";
    }

    /**
     * @return array<string, int>
     */
    protected function defaultQuery(): array
    {
        return ['page' => $this->page];
    }
}
