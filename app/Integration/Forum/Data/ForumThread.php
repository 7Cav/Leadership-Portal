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

        // `first_post` is present because the request asks for it (`with_first_post`); its `message` is
        // the raw BBCode as posted. A response without it is not a thread we can read.
        $message = is_array($data['first_post'] ?? null) ? ($data['first_post']['message'] ?? null) : null;

        if (! is_string($message)) {
            throw ForumRequestException::malformedThread();
        }

        return new self(ForumThreadSummary::fromArray($thread), $message);
    }
}
