<?php

namespace App\Integration\Forum\Data;

use App\Integration\Forum\ForumRequestException;

/**
 * One page of a thread's posts, with where it sits in the thread so a caller can walk every page. Immutable.
 */
readonly class ForumPostPage
{
    /**
     * @param  list<ForumPost>  $posts
     */
    public function __construct(
        public array $posts,
        public int $page,
        public int $lastPage,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the forum's JSON for a page of posts
     */
    public static function fromArray(array $data): self
    {
        $posts = $data['posts'] ?? null;
        // As for a thread list: a missing pagination block would quietly turn "every page" into "page 1".
        $pagination = $data['pagination'] ?? null;

        if (! is_array($posts) || ! is_array($pagination)) {
            throw ForumRequestException::malformedThread();
        }

        $page = $pagination['current_page'] ?? 1;
        $lastPage = $pagination['last_page'] ?? $page;

        return new self(
            posts: array_values(array_map(
                fn ($post) => is_array($post) ? ForumPost::fromArray($post) : throw ForumRequestException::malformedThread(),
                $posts,
            )),
            page: is_numeric($page) ? max(1, (int) $page) : 1,
            lastPage: is_numeric($lastPage) ? max(1, (int) $lastPage) : 1,
        );
    }

    public function hasMore(): bool
    {
        return $this->page < $this->lastPage;
    }
}
