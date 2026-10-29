<?php

namespace App\Repositories;

use App\Models\ChangelogEntry;
use App\Models\ChangelogState;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Sole DB access for `changelog_entries`, `changelog_seen` and `changelog_state` (AD-2). Portal-owned (AD-22): the
 * audited `app/Commands/Changelog` Commands write the entries and the state row; the changelog page writes a user's
 * seen-at, a read marker with no audit entry.
 */
interface ChangelogRepository
{
    /**
     * Every entry, newest date first, the latest written first within a date.
     *
     * @return Collection<int, ChangelogEntry>
     */
    public function all(): Collection;

    public function find(int $id): ?ChangelogEntry;

    public function create(string $date, string $title, string $body, int $userId): ChangelogEntry;

    public function update(ChangelogEntry $entry, string $date, string $title, string $body, int $userId): ChangelogEntry;

    public function delete(ChangelogEntry $entry): ChangelogEntry;

    /**
     * The `date|lowercased title` keys of every entry, to tell an imported entry that already exists.
     *
     * @return array<string, true>
     */
    public function existingKeys(): array;

    /** The key {@see existingKeys()} uses: the date and the title, trimmed and lowercased. */
    public static function key(string $date, string $title): string;

    /** When the newest entry was written, or null with none. */
    public function latestCreatedAt(): ?DateTimeInterface;

    public function seenAt(int $userId): ?DateTimeInterface;

    public function markSeen(int $userId, DateTimeInterface $at): void;

    public function acknowledgedDeployId(): ?string;

    /** Record the deploy the changelog is up to date for. Returns the state row, created on first use. */
    public function acknowledge(?string $deployId): ChangelogState;
}
