<?php

namespace App\Commands\Counseling;

/**
 * What one run of the counseling import (#565) made of the CSV, row by row ({@see ImportRowResult}), and whether it was
 * the dry run or the import itself. After the import, a row still marked import or review is stored (its `recordId`
 * set); one that could not be stored was turned into a skipped row with the reason.
 */
final readonly class ImportReport
{
    /**
     * @param  list<ImportRowResult>  $rows
     */
    public function __construct(
        public array $rows,
        public bool $dryRun,
    ) {}

    /**
     * @return array{all: int, import: int, review: int, skipped: int}
     */
    public function counts(): array
    {
        $count = fn (string $outcome): int => count(array_filter($this->rows, fn (ImportRowResult $row) => $row->outcome === $outcome));

        return [
            'all' => count($this->rows),
            'import' => $count(ImportRowResult::IMPORT),
            'review' => $count(ImportRowResult::REVIEW),
            'skipped' => $count(ImportRowResult::SKIPPED),
        ];
    }

    /** One line for the console and the log. */
    public function summary(): string
    {
        $counts = $this->counts();
        $verb = $this->dryRun ? 'would import' : 'imported';

        return "{$counts['all']} rows: {$verb} {$counts['import']}, {$counts['review']} needing review, {$counts['skipped']} skipped.";
    }

    /**
     * @return array{dryRun: bool, counts: array{all: int, import: int, review: int, skipped: int}, rows: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'dryRun' => $this->dryRun,
            'counts' => $this->counts(),
            'rows' => array_map(fn (ImportRowResult $row) => $row->toArray(), $this->rows),
        ];
    }
}
