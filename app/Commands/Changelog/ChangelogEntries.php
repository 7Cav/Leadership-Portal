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
 * Adding an entry, importing and dismissing all record the current deploy id as acknowledged, which clears the bar for
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

    public function add(User $actor, string $date, string $title, string $body): ChangelogEntry
    {
        $this->authorize($actor);
        [$date, $title, $body] = $this->clean($date, $title, $body);
        $deployId = $this->access->deployId();

        return $this->audit->record(
            actorId: $actor->id,
            action: 'changelog.created',
            reason: null,
            reasonRequired: false,
            mutation: function () use ($actor, $date, $title, $body, $deployId) {
                $entry = $this->changelog->create($date, $title, $body, $actor->id);
                $this->changelog->acknowledge($deployId);

                return $entry;
            },
        );
    }

    public function update(User $actor, ChangelogEntry $entry, string $date, string $title, string $body): ChangelogEntry
    {
        $this->authorize($actor);
        [$date, $title, $body] = $this->clean($date, $title, $body);

        return $this->audit->record(
            actorId: $actor->id,
            action: 'changelog.updated',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->changelog->update($entry, $date, $title, $body, $actor->id),
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
     * title (without case) already exist is left out.
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
                    fn (array $entry) => $this->changelog->create($entry['date'], $entry['title'], $entry['body'], $actor->id),
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
     * The parsed import, each entry marked `duplicate` when its date and title already exist (or appear earlier in the
     * same paste). Writes nothing.
     *
     * @return array{entries: list<array{line: int, date: string, title: string, body: string, duplicate: bool}>, errors: list<array{line: int, message: string}>}
     */
    public function preview(string $markdown): array
    {
        $parsed = $this->parser->parse($markdown);
        $known = $this->changelog->existingKeys();

        $entries = [];
        foreach ($parsed['entries'] as $entry) {
            $key = $this->changelog::key($entry['date'], $entry['title']);
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

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function clean(string $date, string $title, string $body): array
    {
        $title = trim($title);
        $body = trim($body);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Give the date as YYYY-MM-DD.');
        }
        if ($title === '' || mb_strlen($title) > ChangelogEntry::MAX_TITLE_LENGTH) {
            throw new InvalidArgumentException('A title must be 1 to '.ChangelogEntry::MAX_TITLE_LENGTH.' characters.');
        }
        if ($body === '' || mb_strlen($body) > ChangelogEntry::MAX_BODY_LENGTH) {
            throw new InvalidArgumentException('The text must be 1 to '.ChangelogEntry::MAX_BODY_LENGTH.' characters.');
        }

        return [$date, $title, $body];
    }
}
