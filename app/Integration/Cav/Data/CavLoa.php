<?php

namespace App\Integration\Cav\Data;

use App\Integration\Cav\CavRequestException;
use Carbon\CarbonImmutable;

/**
 * One leave of absence as the roster API lists it: one forum thread, already read, matched to a member and
 * classified. Immutable; nothing outside `Integration/` sees the API's wire tokens (`LOA_STATE_OPEN`,
 * `LOA_FORUM_1_7`, ...).
 *
 * `startsOn`/`endsOn` are calendar days (UTC), null when the API had none. `needsReview` is the API's own verdict:
 * a thread it could not take as written, with `reviewReason` saying which rule it tripped. The subject ids are
 * null when the API found no member (it sends "0" for those).
 */
final readonly class CavLoa
{
    public function __construct(
        public int $threadId,
        public ?string $forumUrl,
        public CarbonImmutable $postedAt,
        public ?string $forum,
        public ?int $forumUserId,
        public ?int $cavId,
        public string $username,
        public ?CarbonImmutable $startsOn,
        public ?CarbonImmutable $endsOn,
        public bool $needsReview,
        public ?string $reviewReason,
        public string $reason,
    ) {}

    /**
     * The LOA forum the API names, as the short code the portal stores.
     */
    private const FORUMS = [
        'LOA_FORUM_1_7' => '1-7',
        'LOA_FORUM_2_7' => '2-7',
        'LOA_FORUM_3_7' => '3-7',
        'LOA_FORUM_ACD' => 'acd',
        'LOA_FORUM_REGIMENTAL' => 'regimental',
    ];

    /** The states the portal knows how to read: leave that is open, upcoming or over, and a thread sent for review. */
    private const STATES = ['LOA_STATE_OPEN', 'LOA_STATE_UPCOMING', 'LOA_STATE_ENDED', 'LOA_STATE_NEEDS_REVIEW'];

    /** What each review reason means, in the words a person checking the row reads. */
    private const REVIEW_REASONS = [
        'LOA_REVIEW_REASON_DATES_UNREADABLE' => 'The dates could not be read.',
        'LOA_REVIEW_REASON_END_BEFORE_START' => 'The end date is before the start date.',
        'LOA_REVIEW_REASON_TOO_LONG' => 'The leave is longer than 14 days.',
        'LOA_REVIEW_REASON_NO_SUBJECT' => 'The post has no Username.',
        'LOA_REVIEW_REASON_UNKNOWN_SUBJECT' => 'The Username matches no member.',
    ];

    /**
     * @param  array<string, mixed>  $payload  one entry of the response's `loas` list
     *
     * @throws CavRequestException when the entry has no usable thread id or post date, so it cannot be stored
     */
    public static function fromPayload(array $payload): self
    {
        $threadId = self::positiveInteger($payload['threadId'] ?? null)
            ?? throw CavRequestException::malformedLoa('it had no usable `threadId`');

        $posted = $payload['postDate'] ?? null;

        if (! is_int($posted) && ! (is_string($posted) && ctype_digit($posted))) {
            throw CavRequestException::malformedLoa("thread {$threadId} had no usable `postDate`");
        }

        $subject = is_array($payload['subject'] ?? null) ? $payload['subject'] : [];
        $state = is_string($payload['state'] ?? null) ? $payload['state'] : '';

        // Only a state it knows is taken as leave. A missing one, or a state a newer API adds, is a record the portal
        // cannot vouch for, so it is skipped (the run is partial and deletes nothing) rather than excusing a trooper.
        if (! in_array($state, self::STATES, true)) {
            throw CavRequestException::malformedLoa("thread {$threadId} had an unknown `state` ('{$state}')");
        }
        $reviewCode = is_string($payload['reviewReason'] ?? null) ? $payload['reviewReason'] : '';
        $start = self::day($payload['startDate'] ?? null);
        $end = self::day($payload['endDate'] ?? null);

        // A date the API sent that is not a day is a broken record, not a missing one: never guessed at.
        if ($start === false || $end === false) {
            throw CavRequestException::malformedLoa("thread {$threadId} had a date that is not YYYY-MM-DD");
        }

        $needsReview = $state === 'LOA_STATE_NEEDS_REVIEW';

        // The API itself sends a thread with its end before its start for review. One that arrives as leave is a
        // record that contradicts itself.
        if (! $needsReview && $start !== null && $end !== null && $end->lessThan($start)) {
            throw CavRequestException::malformedLoa("thread {$threadId} ends before it starts");
        }

        return new self(
            threadId: $threadId,
            forumUrl: is_string($payload['forumUrl'] ?? null) && $payload['forumUrl'] !== '' ? $payload['forumUrl'] : null,
            postedAt: CarbonImmutable::createFromTimestampUTC((int) $posted),
            forum: self::FORUMS[$payload['forum'] ?? ''] ?? null,
            forumUserId: self::positiveInteger($subject['userId'] ?? null),
            cavId: self::positiveInteger($subject['milpacId'] ?? null),
            username: is_string($subject['username'] ?? null) ? $subject['username'] : '',
            startsOn: $start,
            endsOn: $end,
            needsReview: $needsReview,
            reviewReason: $needsReview ? (self::REVIEW_REASONS[$reviewCode] ?? 'The roster API flagged the thread for review.') : null,
            reason: is_string($payload['reason'] ?? null) ? $payload['reason'] : '',
        );
    }

    /** The API sends ids as strings of digits, "0" meaning none. */
    private static function positiveInteger(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 ? $value : null;
    }

    /** @return CarbonImmutable|null|false the day, null for an empty one, false for text that is not a day */
    private static function day(mixed $value): CarbonImmutable|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return false;
        }

        return CarbonImmutable::parse($value, 'UTC')->startOfDay();
    }
}
