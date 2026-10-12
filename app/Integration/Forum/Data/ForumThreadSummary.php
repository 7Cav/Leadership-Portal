<?php

namespace App\Integration\Forum\Data;

use App\Integration\Forum\ForumRequestException;
use Carbon\CarbonImmutable;

/**
 * One row of a forum section's thread list (`GET /api/forums/{id}/threads`): enough to know which
 * threads exist and to decide whether one needs fetching, without its body. Immutable; the invariants
 * (a positive thread id and node id, a title) are checked at construction so no instance can carry a
 * bogus key.
 */
readonly class ForumThreadSummary
{
    public function __construct(
        public int $threadId,
        public int $nodeId,
        public string $title,
        public CarbonImmutable $postedAt,
        public string $discussionState,
        public ?string $url = null,
    ) {
        if ($threadId <= 0 || $nodeId <= 0) {
            throw ForumRequestException::malformedThread();
        }
    }

    /**
     * @param  array<string, mixed>  $data  one thread object from the forum's JSON
     */
    public static function fromArray(array $data): self
    {
        $threadId = $data['thread_id'] ?? null;
        $nodeId = $data['node_id'] ?? null;
        $title = $data['title'] ?? null;
        $postDate = $data['post_date'] ?? null;

        if (! is_numeric($threadId) || ! is_numeric($nodeId) || ! is_string($title) || ! is_numeric($postDate) || ! is_string($data['discussion_state'] ?? null)) {
            throw ForumRequestException::malformedThread();
        }

        $url = $data['view_url'] ?? null;
        // Required, not defaulted: a thread whose state is unknown must not pass for a live one, and a
        // payload that omits it should fail loudly on the first real run rather than quietly read
        // deleted or moderated threads as leave.
        $state = $data['discussion_state'] ?? null;

        return new self(
            threadId: (int) $threadId,
            nodeId: (int) $nodeId,
            title: $title,
            postedAt: CarbonImmutable::createFromTimestampUTC((int) $postDate),
            url: is_string($url) && $url !== '' ? $url : null,
            discussionState: $state,
        );
    }

    /** Whether the thread is an ordinary, visible one: deleted and moderated threads are not leave records. */
    public function isVisible(): bool
    {
        return $this->discussionState === 'visible';
    }
}
