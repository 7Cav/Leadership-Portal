<?php

namespace App\Integration\Forum\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Lists one forum section's threads, a page at a time, newest first. `GET {base}/api/forums/{node}/threads`,
 * read with the forum API key (applied by ForumApi). Sorted by when the thread was created
 * (`order=post_date`, not the default last reply), so a caller can stop paging at the first page whose
 * threads all predate what it already has: a reply to an old thread never moves it back to the top.
 * The forum returns pinned threads separately, in `sticky`, so they are not part of this list.
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
     * @return array<string, int|string>
     */
    protected function defaultQuery(): array
    {
        return ['page' => $this->page, 'order' => 'post_date', 'direction' => 'desc'];
    }
}
