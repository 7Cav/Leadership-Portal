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
     * Every entry, newest date first, the latest written first within a date. Without `$includeTechnical`, technical
     * entries are left out.
     *
     * @return Collection<int, ChangelogEntry>
     */
    public function all(bool $includeTechnical = true): Collection;

    public function find(int $id): ?ChangelogEntry;

    public function create(string $date, string $body, ?string $details, int $userId, bool $major = false, bool $technical = false): ChangelogEntry;

    public function update(ChangelogEntry $entry, string $date, string $body, ?string $details, int $userId, bool $major = false, bool $technical = false): ChangelogEntry;

    /** Remove one entry for good. */
    public function delete(ChangelogEntry $entry): ChangelogEntry;

    /**
     * Delete every entry, restorable until `$at` plus {@see ChangelogEntry::RESTORE_HOURS} hours. Entries an earlier delete
     * all removed that can still be restored join this one, so one restore brings back both. Returns every entry a
     * restore would now bring back, oldest first.
     *
     * @return Collection<int, ChangelogEntry>
     */
    public function deleteAll(DateTimeInterface $at): Collection;

    /**
     * The entries a restore would bring back on `$now`: deleted by a delete all less than
     * {@see ChangelogEntry::RESTORE_HOURS} hours ago. Oldest first.
     *
     * @return Collection<int, ChangelogEntry>
     */
    public function restorable(DateTimeInterface $now): Collection;

    /**
     * Bring back every restorable entry as it was. Returns them, oldest first.
     *
     * @return Collection<int, ChangelogEntry>
     */
    public function restore(DateTimeInterface $now): Collection;

    /** Remove for good every entry a delete all removed more than {@see ChangelogEntry::RESTORE_HOURS} hours before `$now`. Returns how many. */
    public function purgeDeleted(DateTimeInterface $now): int;

    /**
     * The `date|lowercased text` keys of every entry, to tell an imported entry that already exists.
     *
     * @return array<string, true>
     */
    public function existingKeys(): array;

    /** The key {@see existingKeys()} uses: the date and the change text, trimmed and lowercased. */
    public static function key(string $date, string $body): string;

    /** When the newest entry was written, or null with none. Without `$includeTechnical`, technical entries are left out. */
    public function latestCreatedAt(bool $includeTechnical = true): ?DateTimeInterface;

    public function seenAt(int $userId): ?DateTimeInterface;

    public function markSeen(int $userId, DateTimeInterface $at): void;

    public function acknowledgedDeployId(): ?string;

    /** Record the deploy the changelog is up to date for. Returns the state row, created on first use. */
    public function acknowledge(?string $deployId): ChangelogState;

    /** The Admin setting: whether technical entries are available to everyone. Off with no state row yet. */
    public function technicalEnabled(): bool;

    /** Change the Admin setting. Returns the state row, created on first use. */
    public function setTechnicalEnabled(bool $enabled): ChangelogState;
}
