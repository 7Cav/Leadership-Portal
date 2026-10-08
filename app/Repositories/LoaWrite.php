<?php

namespace App\Repositories;

use App\Models\LoaParseStatus;
use App\Models\LoaType;
use Carbon\CarbonImmutable;

/**
 * One leave thread as the sync wants it stored: everything a `loas` row holds, so the repository is the
 * only place that knows the columns. Immutable. The dates are calendar days.
 */
final readonly class LoaWrite
{
    public function __construct(
        public int $forumThreadId,
        public int $forumNodeId,
        public ?int $trooperId,
        public LoaType $type,
        public ?CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public LoaParseStatus $parseStatus,
        public ?string $reviewReason,
        public string $sourceThread,
        public ?string $rawUsername,
        public ?string $rawStartDate,
        public ?string $rawEndDate,
        public ?string $rawExcerpt,
    ) {}
}
