<?php

namespace App\Integration\Forum\Data;

use App\Integration\Forum\ForumRequestException;
use Carbon\CarbonImmutable;

/**
 * One post of a forum thread, with its author. Immutable. The body is the raw BBCode as posted; reading an answer from
 * it is the caller's job, not this seam's. `userId` is 0 for a guest post, which no trooper can be matched to.
 */
readonly class ForumPost
{
    public function __construct(
        public int $postId,
        public int $userId,
        public string $username,
        public string $message,
        public CarbonImmutable $postedAt,
        public bool $isFirstPost,
        public string $messageState,
        public ?string $url = null,
    ) {
        if ($postId <= 0) {
            throw ForumRequestException::malformedThread();
        }
    }

    /**
     * @param  array<string, mixed>  $data  one post object from the forum's JSON
     */
    public static function fromArray(array $data): self
    {
        $postId = $data['post_id'] ?? null;
        $userId = $data['user_id'] ?? 0;
        $username = $data['username'] ?? '';
        $message = $data['message'] ?? null;
        $postDate = $data['post_date'] ?? null;
        // Required, not defaulted, as a thread's state is: a post whose state is unknown must not pass for a visible one.
        $state = $data['message_state'] ?? null;

        if (! is_numeric($postId) || ! is_numeric($userId) || ! is_string($username) || ! is_string($message) || ! is_numeric($postDate) || ! is_string($state)) {
            throw ForumRequestException::malformedThread();
        }

        // The thread's opening post is the roll call itself. The forum flags it, and gives every post its place in the
        // thread (`position`, 0 for the first).
        $first = ($data['is_first_post'] ?? null) === true || (isset($data['position']) && is_numeric($data['position']) && (int) $data['position'] === 0);
        $url = $data['view_url'] ?? null;

        return new self(
            postId: (int) $postId,
            userId: (int) $userId,
            username: $username,
            message: $message,
            postedAt: CarbonImmutable::createFromTimestampUTC((int) $postDate),
            isFirstPost: $first,
            messageState: $state,
            url: is_string($url) && $url !== '' ? $url : null,
        );
    }

    /** Whether the post is an ordinary, visible one: deleted and moderated posts are not read. */
    public function isVisible(): bool
    {
        return $this->messageState === 'visible';
    }
}
