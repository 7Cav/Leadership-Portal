<?php

namespace App\Sync\Loa;

use App\Models\LoaParseStatus;
use Carbon\CarbonImmutable;

/**
 * What {@see LoaPostParser} read from one leave thread. Immutable. `reasons` is empty exactly when the
 * thread was read cleanly; any entry means a person should look, and the thread asserts no dates.
 *
 * `startsAt`/`endsAt` are only set for a normal LOA, whose dates are in the post. An ELOA post carries no
 * start date (it is the day the PAF was submitted, found by following `pafThreadId`) and its
 * `Expected return date` is only an expectation, so it stays in `rawEnd` and never becomes `endsAt`.
 */
final readonly class ParsedLoaThread
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public LoaThreadKind $kind,
        public ?string $username,
        public ?int $forumUserId,
        public ?CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public ?string $rawStart,
        public ?string $rawEnd,
        public ?int $pafThreadId,
        public string $excerpt,
        public array $reasons,
    ) {}

    public function status(): LoaParseStatus
    {
        return $this->reasons === [] ? LoaParseStatus::Ok : LoaParseStatus::NeedsReview;
    }

    /** All reasons in one line, for the `review_reason` column. */
    public function reason(): ?string
    {
        return $this->reasons === [] ? null : mb_substr(implode(' ', $this->reasons), 0, 250);
    }
}
