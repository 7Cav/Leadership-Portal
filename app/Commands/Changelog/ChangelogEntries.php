<?php

namespace App\Commands\Changelog;

use App\Authorization\ChangelogAccess;
use App\Models\ChangelogEntry;
use App\Models\User;
use App\Repositories\AuditedBatch;
use App\Repositories\ChangelogRepository;
use App\Support\Audit\AuditEnvelope;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Every write to the changelog (AD-16): add, edit, delete, delete all, restore, bulk import and dismissing the
 * "Changelog outdated" bar.
 * Each is checked against {@see ChangelogAccess::mayEdit()} before anything is written, so a non-editor, someone acted
 * as, or a view-as preview is refused whatever the caller forgot to gate. Only a delete needs a reason.
 *
 * An entry is one change on a date: one line of text, optional Markdown details, a `major` flag and a `technical` flag
 * (both default off, never both on). Adding (a whole date's batch at
 * once), importing and dismissing all record the current deploy id as acknowledged, which clears the bar for
 * every editor.
 */
class ChangelogEntries
{
    public function __construct(
        private readonly ChangelogRepository $changelog,
        private readonly AuditEnvelope $audit,
        private readonly ChangelogAccess $access,
        private readonly ChangelogImportParser $parser,
    ) {}

    /**
     * Add one date's batch of entries, in the order given, in one audited action.
     *
     * @param  list<array{body: string, details?: ?string, major?: bool, technical?: bool}>  $items
     * @return list<ChangelogEntry>
     *
     * @throws InvalidArgumentException on a bad date, no entries, too many, or an entry that is empty, too long, or both major and technical.
     */
    public function add(User $actor, string $date, array $items): array
    {
        $this->authorize($actor);
        $date = $this->cleanDate($date);
        if ($items === []) {
            throw new InvalidArgumentException('Add at least one entry.');
        }
        if (count($items) > ChangelogEntry::MAX_BATCH) {
            throw new InvalidArgumentException('Add at most '.ChangelogEntry::MAX_BATCH.' entries at once.');
        }
        $items = array_map(fn (array $item) => [
            ...$this->cleanEntry($item['body'], $item['details'] ?? null),
            ...$this->kind((bool) ($item['major'] ?? false), (bool) ($item['technical'] ?? false)),
        ], array_values($items));
        $deployId = $this->access->deployId();

        $saved = [];
        $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'changelog.created',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($actor, $date, $items, $deployId, &$saved) {
                $saved = array_map(fn (array $item) => $this->changelog->create($date, $item[0], $item[1], $actor->id, $item[2], $item[3]), $items);

                return new AuditedBatch($this->changelog->acknowledge($deployId), ...$saved);
            },
        );

        return $saved;
    }

    public function update(User $actor, ChangelogEntry $entry, string $date, string $body, ?string $details, bool $major = false, bool $technical = false): ChangelogEntry
    {
        $this->authorize($actor);
        $date = $this->cleanDate($date);
        [$body, $details] = $this->cleanEntry($body, $details);
        $this->kind($major, $technical);

        return $this->audit->record(
            actorId: $actor->id,
            action: 'changelog.updated',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->changelog->update($entry, $date, $body, $details, $actor->id, $major, $technical),
        );
    }

    public function delete(User $actor, ChangelogEntry $entry, ?string $reason): ChangelogEntry
    {
        $this->authorize($actor);

        return $this->audit->record(
            actorId: $actor->id,
            action: 'changelog.deleted',
            reason: $reason === null ? null : trim($reason),
            reasonRequired: true,
            mutation: fn () => $this->changelog->delete($entry),
        );
    }

    /**
     * Delete every entry in one audited action. They can be restored for {@see ChangelogEntry::RESTORE_HOURS} hours; entries
     * an earlier delete all removed that can still be restored join this one, with the window starting again.
     *
     * @return int how many entries a restore would now bring back
     *
     * @throws InvalidArgumentException when there is nothing to delete.
     */
    public function deleteAll(User $actor): int
    {
        $this->authorize($actor);
        if ($this->changelog->all()->isEmpty()) {
            throw new InvalidArgumentException('There are no entries to delete.');
        }

        $deleted = collect();
        $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'changelog.deleted_all',
            reason: null,
            reasonRequired: false,
            mutation: function () use (&$deleted) {
                $deleted = $this->changelog->deleteAll(now());

                return new AuditedBatch(...$deleted->all());
            },
        );

        return $deleted->count();
    }

    /**
     * Bring back every entry the last delete all removed, as it was, in one audited action.
     *
     * @return int how many entries came back
     *
     * @throws InvalidArgumentException when nothing can be restored, or the 72 hours have passed.
     */
    public function restoreDeleted(User $actor): int
    {
        $this->authorize($actor);
        if ($this->changelog->restorable(now())->isEmpty()) {
            throw new InvalidArgumentException('There is nothing to restore. Deleted entries can be restored for '.ChangelogEntry::RESTORE_HOURS.' hours.');
        }

        $restored = collect();
        $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'changelog.restored',
            reason: null,
            reasonRequired: false,
            mutation: function () use (&$restored) {
                $restored = $this->changelog->restore(now());

                return new AuditedBatch(...$restored->all());
            },
        );

        return $restored->count();
    }

    /**
     * Save what the pasted Markdown holds, in one audited batch. A change without an id is added (with `$skipDuplicates`,
     * one whose date and change text, without case, already exist is left out). A change with an id (`{#42}`, as the
     * export writes it) updates that entry's date, text, details and flags in place, keeping its id and when it was
     * first written, so it does not count as new; one that already reads the same is left alone. Only an import that
     * adds something clears the "Changelog outdated" bar: rewording is not a new change.
     *
     * @return array{created: int, updated: int, unchanged: int}
     *
     * @throws InvalidArgumentException when the Markdown has an error (an unknown id among them), or nothing would change.
     */
    public function import(User $actor, string $markdown, bool $skipDuplicates): array
    {
        $this->authorize($actor);

        $preview = $this->preview($markdown);
        if ($preview['errors'] !== []) {
            throw new InvalidArgumentException($preview['errors'][0]['message']);
        }

        $new = array_values(array_filter($preview['entries'], fn (array $entry) => $entry['status'] === 'new' && ! ($skipDuplicates && $entry['duplicate'])));
        $updated = array_values(array_filter($preview['entries'], fn (array $entry) => $entry['status'] === 'updated'));
        $unchanged = count(array_filter($preview['entries'], fn (array $entry) => $entry['status'] === 'unchanged'));
        if ($new === [] && $updated === []) {
            throw new InvalidArgumentException('Every entry already exists as written. Nothing was imported.');
        }

        $current = $this->changelog->findMany(array_map(fn (array $entry) => $entry['id'], $updated));
        $deployId = $this->access->deployId();

        $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'changelog.imported',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($actor, $new, $updated, $current, $deployId) {
                $changed = array_map(function (array $entry) use ($actor, $current) {
                    [$body, $details] = $this->cleanEntry($entry['body'], $entry['details']);

                    return $this->changelog->update($current[$entry['id']], $entry['date'], $body, $details, $actor->id, $entry['major'], $entry['technical']);
                }, $updated);
                // Oldest first, so ids follow the dates.
                $saved = array_map(
                    fn (array $entry) => $this->changelog->create($entry['date'], $entry['body'], $entry['details'], $actor->id, $entry['major'], $entry['technical']),
                    array_reverse($new),
                );

                $target = $saved === [] ? array_shift($changed) : $this->changelog->acknowledge($deployId);

                return new AuditedBatch($target, ...$changed, ...$saved);
            },
        );

        return ['created' => count($new), 'updated' => count($updated), 'unchanged' => $unchanged];
    }

    public function dismissOutdated(User $actor): void
    {
        $this->authorize($actor);
        $deployId = $this->access->deployId();

        $this->audit->record(
            actorId: $actor->id,
            action: 'changelog.outdated_dismissed',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->changelog->acknowledge($deployId),
        );
    }

    /**
     * The parsed import. Each entry has a `status`: `new` (no id), `updated` (its id names an entry that reads
     * differently) or `unchanged` (its id names an entry that already reads the same). A new entry is marked `duplicate`
     * when its date and change text already exist (or appear earlier in the same paste). An id no entry has, or one
     * given twice, is a line error. Writes nothing.
     *
     * @return array{entries: list<array{line: int, date: string, body: string, details: ?string, major: bool, technical: bool, id: ?int, status: string, duplicate: bool}>, errors: list<array{line: int, message: string}>}
     */
    public function preview(string $markdown): array
    {
        $parsed = $this->parser->parse($markdown);
        $errors = $parsed['errors'];
        $known = $this->changelog->existingKeys();
        $ids = array_values(array_unique(array_filter(array_column($parsed['entries'], 'id'), fn ($id) => $id !== null)));
        $existing = $this->changelog->findMany($ids);

        $entries = [];
        $seen = [];
        foreach ($parsed['entries'] as $entry) {
            $id = $entry['id'];
            if ($id === null) {
                $key = $this->changelog::key($entry['date'], $entry['body']);
                $entries[] = [...$entry, 'status' => 'new', 'duplicate' => isset($known[$key])];
                $known[$key] = true;

                continue;
            }
            if (isset($seen[$id])) {
                $errors[] = ['line' => $entry['line'], 'message' => "Line {$entry['line']}: the id {#{$id}} is also on line {$seen[$id]}. Each entry can appear once."];

                continue;
            }
            $seen[$id] = $entry['line'];
            $current = $existing[$id] ?? null;
            if ($current === null) {
                $errors[] = ['line' => $entry['line'], 'message' => "Line {$entry['line']}: no entry has the id {#{$id}}. Remove the id to add it as a new entry."];

                continue;
            }
            $entries[] = [...$entry, 'status' => $this->same($current, $entry) ? 'unchanged' : 'updated', 'duplicate' => false];
        }

        usort($errors, fn (array $a, array $b) => $a['line'] <=> $b['line']);

        return ['entries' => $entries, 'errors' => $errors];
    }

    /**
     * Whether the imported change already reads like the entry. Details are compared without trailing spaces on a line
     * and with any line ending, the way the import reads them back from an export.
     *
     * @param  array{date: string, body: string, details: ?string, major: bool, technical: bool}  $entry
     */
    private function same(ChangelogEntry $current, array $entry): bool
    {
        $details = fn (?string $text) => $text === null ? null : implode("\n", array_map('rtrim', preg_split('/\r\n|\r|\n/', trim($text)) ?: []));
        $body = trim(preg_replace('/\s+/u', ' ', $entry['body']) ?? '');

        return $current->date->toDateString() === $entry['date']
            && $current->body === $body
            && $details($current->details) === $details($entry['details'])
            && (bool) $current->major === $entry['major']
            && (bool) $current->technical === $entry['technical'];
    }

    private function authorize(User $actor): void
    {
        if (! $this->access->mayEdit($actor)) {
            throw new AuthorizationException('Only the changelog editors may change the changelog, signed in as themselves.');
        }
    }

    private function cleanDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Give the date as YYYY-MM-DD.');
        }

        return $date;
    }

    /**
     * The two flags, refused when both are on: a technical entry is never a major one.
     *
     * @return array{0: bool, 1: bool}
     */
    private function kind(bool $major, bool $technical): array
    {
        if ($major && $technical) {
            throw new InvalidArgumentException('An entry cannot be both major and technical.');
        }

        return [$major, $technical];
    }

    /**
     * The change text as one trimmed line and the details trimmed, or null when blank.
     *
     * @return array{0: string, 1: ?string}
     */
    private function cleanEntry(string $body, ?string $details): array
    {
        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
        $details = $details === null ? null : trim($details);
        $details = $details === '' ? null : $details;

        if ($body === '' || mb_strlen($body) > ChangelogEntry::MAX_BODY_LENGTH) {
            throw new InvalidArgumentException('Each change must be 1 to '.ChangelogEntry::MAX_BODY_LENGTH.' characters.');
        }
        if ($details !== null && mb_strlen($details) > ChangelogEntry::MAX_DETAILS_LENGTH) {
            throw new InvalidArgumentException('Additional information must be at most '.ChangelogEntry::MAX_DETAILS_LENGTH.' characters.');
        }

        return [$body, $details];
    }
}
