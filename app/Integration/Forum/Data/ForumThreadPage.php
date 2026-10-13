<?php

namespace App\Integration\Forum\Data;

use App\Integration\Forum\ForumRequestException;

/**
 * One page of a forum section's threads, with where it sits in the listing so a caller can walk
 * every page. Immutable.
 */
readonly class ForumThreadPage
{
    /**
     * @param  list<ForumThreadSummary>  $threads
     */
    public function __construct(
        public array $threads,
        public int $page,
        public int $lastPage,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the forum's JSON for a thread list
     */
    public static function fromArray(array $data): self
    {
        // Pinned threads arrive separately under `sticky`; they are not read, since a pinned thread is a
        // notice (how to file a request), not a request.
        $threads = $data['threads'] ?? null;

        if (! is_array($threads)) {
            throw ForumRequestException::malformedThread();
        }

        // A missing pagination block would quietly turn "walk every page" into "read page 1", so it is
        // treated as an unusable payload like any other missing field.
        $pagination = $data['pagination'] ?? null;

        if (! is_array($pagination)) {
            throw ForumRequestException::malformedThread();
        }

        $page = $pagination['current_page'] ?? 1;
        $lastPage = $pagination['last_page'] ?? $page;

        return new self(
            threads: array_values(array_map(
                fn ($thread) => is_array($thread) ? ForumThreadSummary::fromArray($thread) : throw ForumRequestException::malformedThread(),
                $threads,
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
