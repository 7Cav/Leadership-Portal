<?php

namespace App\Integration\Forum\Data;

use App\Integration\Forum\ForumRequestException;

/**
 * One forum thread with the body of its first post, which is where a leave request's fields live.
 * Immutable. The body is the raw BBCode as posted; reading it is the sync's job (4.2), not this
 * seam's.
 */
readonly class ForumThread
{
    public function __construct(
        public ForumThreadSummary $summary,
        public string $firstPostBody,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the forum's JSON for one thread
     */
    public static function fromArray(array $data): self
    {
        $thread = $data['thread'] ?? null;

        if (! is_array($thread)) {
            throw ForumRequestException::malformedThread();
        }

        // The first post arrives alongside the thread; tolerate it under `first_post` or as the head
        // of a `posts` list, since both shapes appear in the forum's thread views.
        $firstPost = is_array($data['first_post'] ?? null)
            ? $data['first_post']
            : (is_array($data['posts'][0] ?? null) ? $data['posts'][0] : null);
        $message = $firstPost['message'] ?? null;

        if (! is_string($message)) {
            throw ForumRequestException::malformedThread();
        }

        return new self(ForumThreadSummary::fromArray($thread), $message);
    }
}
