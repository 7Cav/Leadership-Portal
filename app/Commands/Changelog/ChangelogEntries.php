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
 * Every write to the changelog (AD-16): add, edit, delete, bulk import and dismissing the "Changelog outdated" bar.
 * Each is checked against {@see ChangelogAccess::mayEdit()} before anything is written, so a non-editor, someone acted
 * as, or a view-as preview is refused whatever the caller forgot to gate. Only a delete needs a reason.
 *
 * An entry is one change on a date: one line of text, optional Markdown details and a `major` flag (default off). Adding (a whole date's batch at
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
     * @param  list<array{body: string, details?: ?string, major?: bool}>  $items
     * @return list<ChangelogEntry>
     *
     * @throws InvalidArgumentException on a bad date, no entries, too many, or an entry that is empty or too long.
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
        $items = array_map(fn (array $item) => [...$this->cleanEntry($item['body'], $item['details'] ?? null), (bool) ($item['major'] ?? false)], array_values($items));
        $deployId = $this->access->deployId();

        $saved = [];
        $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'changelog.created',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($actor, $date, $items, $deployId, &$saved) {
                $saved = array_map(fn (array $item) => $this->changelog->create($date, $item[0], $item[1], $actor->id, $item[2]), $items);

                return new AuditedBatch($this->changelog->acknowledge($deployId), ...$saved);
            },
        );

        return $saved;
    }

    public function update(User $actor, ChangelogEntry $entry, string $date, string $body, ?string $details, bool $major = false): ChangelogEntry
    {
        $this->authorize($actor);
        $date = $this->cleanDate($date);
        [$body, $details] = $this->cleanEntry($body, $details);

        return $this->audit->record(
            actorId: $actor->id,
            action: 'changelog.updated',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->changelog->update($entry, $date, $body, $details, $actor->id, $major),
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
     * Save every entry the pasted Markdown holds, in one audited batch. With `$skipDuplicates`, an entry whose date and
     * change text (without case) already exist is left out.
     *
     * @return int how many entries were saved
     *
     * @throws InvalidArgumentException when the Markdown has an error, or nothing is left to save.
     */
    public function import(User $actor, string $markdown, bool $skipDuplicates): int
    {
        $this->authorize($actor);

        $preview = $this->preview($markdown);
        if ($preview['errors'] !== []) {
            throw new InvalidArgumentException($preview['errors'][0]['message']);
        }

        $entries = array_values(array_filter($preview['entries'], fn (array $entry) => ! ($skipDuplicates && $entry['duplicate'])));
        if ($entries === []) {
            throw new InvalidArgumentException('Every entry already exists. Nothing was imported.');
        }

        $deployId = $this->access->deployId();

        $this->audit->recordBatch(
            actorId: $actor->id,
            action: 'changelog.imported',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($actor, $entries, $deployId) {
                // Oldest first, so ids follow the dates.
                $saved = array_map(
                    fn (array $entry) => $this->changelog->create($entry['date'], $entry['body'], $entry['details'], $actor->id, $entry['major']),
                    array_reverse($entries),
                );

                return new AuditedBatch($this->changelog->acknowledge($deployId), ...$saved);
            },
        );

        return count($entries);
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
     * The parsed import, each entry marked `duplicate` when its date and change text already exist (or appear earlier in
     * the same paste). Writes nothing.
     *
     * @return array{entries: list<array{line: int, date: string, body: string, details: ?string, major: bool, duplicate: bool}>, errors: list<array{line: int, message: string}>}
     */
    public function preview(string $markdown): array
    {
        $parsed = $this->parser->parse($markdown);
        $known = $this->changelog->existingKeys();

        $entries = [];
        foreach ($parsed['entries'] as $entry) {
            $key = $this->changelog::key($entry['date'], $entry['body']);
            $entries[] = [...$entry, 'duplicate' => isset($known[$key])];
            $known[$key] = true;
        }

        return ['entries' => $entries, 'errors' => $parsed['errors']];
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
