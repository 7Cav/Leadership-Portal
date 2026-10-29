<?php

namespace App\Sync\Loa;

use App\Models\LoaParseStatus;
use Carbon\CarbonImmutable;

/**
 * What {@see LoaPostParser} read from one leave thread. Immutable. `reasons` is empty exactly when the
 * thread was read cleanly; any entry means a person should look, and the thread asserts no dates.
 *
 * `startsAt`/`endsAt` are only set for a normal LOA, whose dates are in the post. An ELOA post carries no
 * start date (it is the day the PAF was submitted, found by following `pafThreadId`). Its `Expected return date`
 * is only an expectation, since an ELOA can be extended: it is read into `expectedReturnAt` for display (null when
 * it cannot be read, which is not a reason to review the thread) and never becomes `endsAt`.
 *
 * `reviewStartsAt`/`reviewEndsAt` are the dates an LOA post that needs review still gives, when both can be read,
 * the end is not before the start, and both are within three years of today; the end is cut to 14 days after the
 * start, the longest an LOA runs. They assert no leave: they only say which weeks the needs-review shield covers.
 * Null on a thread read cleanly.
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
        public ?CarbonImmutable $expectedReturnAt,
        public ?int $pafThreadId,
        public string $excerpt,
        public array $reasons,
        public ?CarbonImmutable $reviewStartsAt = null,
        public ?CarbonImmutable $reviewEndsAt = null,
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
