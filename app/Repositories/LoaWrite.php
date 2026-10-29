<?php

namespace App\Repositories;

use App\Models\LoaParseStatus;
use App\Models\LoaType;
use Carbon\CarbonImmutable;

/**
 * One leave thread as the sync wants it stored: everything a `loas` row holds, so the repository is the
 * only place that knows the columns. Immutable. The dates are calendar days; `postedAt` is the moment the
 * thread was posted on the forum. `reviewStartsAt`/`reviewEndsAt` are set only on a row that needs review whose
 * post still gives usable leave dates (see ParsedLoaThread): they place its shield and never assert leave.
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
        public ?CarbonImmutable $expectedReturnAt = null,
        public ?CarbonImmutable $postedAt = null,
        public ?CarbonImmutable $reviewStartsAt = null,
        public ?CarbonImmutable $reviewEndsAt = null,
    ) {}
}
