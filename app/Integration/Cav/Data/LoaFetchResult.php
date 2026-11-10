<?php

namespace App\Integration\Cav\Data;

use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * What one read of the roster API's LOA list returned. `loas` are the records that could be used; `errors` say why
 * the others could not (one per skipped record), so the caller can tell a complete snapshot from one with holes:
 * a thread missing from a list with holes may be deleted, or may only be unreadable, so nothing may be concluded
 * from its absence. `endedSince` is the window the read asked for, `fetchedAt` when it was made.
 */
final readonly class LoaFetchResult
{
    /**
     * @param  list<CavLoa>  $loas
     * @param  list<string>  $errors
     */
    public function __construct(
        public array $loas,
        public array $errors,
        public ?CarbonImmutable $endedSince,
        public DateTimeImmutable $fetchedAt,
    ) {}

    public function isComplete(): bool
    {
        return $this->errors === [];
    }
}
