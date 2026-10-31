<?php

namespace App\Commands\Counseling;

use App\Models\CounselingKind;
use App\Models\CounselingType;
use Carbon\CarbonImmutable;

/**
 * What the counseling import (#565) made of one CSV row: **import** (stored as it is), **review** (stored, flagged for
 * someone to fill in its fields by hand, or, on the Admin screen, waiting for the Admin to pick which of several
 * troopers it is), or **skipped** (left out, `$reason` saying why). `$notes` are what the Admin should know about a row
 * that is imported all the same, such as an issuer nobody matched.
 *
 * Built by {@see ImportCounselingRecordsHandler}; only {@see toArray()} leaves it, for the page and the console. The
 * resolved ids, the form data and the file path stay on the server.
 */
final class ImportRowResult
{
    public const IMPORT = 'import';

    public const REVIEW = 'review';

    public const SKIPPED = 'skipped';

    /**
     * @param  list<string>  $notes
     * @param  list<array{id: int, name: string}>  $candidates  the troopers the row's Last.F matched, when several did
     * @param  array<string, mixed>|null  $formData
     */
    public function __construct(
        public readonly int $line,
        public readonly string $source,
        public readonly string $label,
        public string $outcome = self::IMPORT,
        public ?string $reason = null,
        public array $notes = [],
        public ?CounselingType $type = null,
        public ?CounselingKind $kind = null,
        public ?CarbonImmutable $counseledOn = null,
        public ?int $trooperId = null,
        public ?string $trooperName = null,
        public ?int $issuerId = null,
        public ?string $issuerName = null,
        public array $candidates = [],
        public ?array $formData = null,
        public ?string $path = null,
        public ?string $documentName = null,
        public ?string $importKey = null,
        public ?int $recordId = null,
    ) {}

    public function skip(string $reason): self
    {
        $this->outcome = self::SKIPPED;
        $this->reason = $reason;

        return $this;
    }

    public function isSkipped(): bool
    {
        return $this->outcome === self::SKIPPED;
    }

    /** Whether the import stores this row: imported as it is, or flagged for review. */
    public function isStored(): bool
    {
        return $this->outcome !== self::SKIPPED;
    }

    /**
     * @return array{line: int, source: string, label: string, outcome: string, reason: ?string, notes: list<string>, type: ?string, trooper: ?string, issuer: ?string, counseledOn: ?string, candidates: list<array{id: int, name: string}>, recordId: ?int}
     */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'source' => $this->source,
            'label' => $this->label,
            'outcome' => $this->outcome,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'type' => $this->type?->value,
            'trooper' => $this->trooperName,
            'issuer' => $this->issuerName,
            'counseledOn' => $this->counseledOn?->toDateString(),
            'candidates' => $this->candidates,
            'recordId' => $this->recordId,
        ];
    }
}
