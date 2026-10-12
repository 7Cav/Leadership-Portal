<?php

namespace App\Loa;

use App\Models\Loa;
use App\Models\LoaType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * One trooper's LOA coverage for one Cav week, as {@see LoaCoverage} resolved it (Story 4.4). Every
 * consumer (the badge, the heatmap, the at-risk helper, the KPI service) asks this object rather than
 * reading `loas` and re-deciding what a row means.
 *
 * - `Covered`: a cleanly read leave touches the week. The absence is excused, and the dates are asserted. An ELOA
 *   with no return yet stays covered past its expected return date, since it can be extended.
 * - `NeedsReview`: a leave exists that could not be read. No date and no absence is asserted, and no
 *   at-risk or AWOL attribution is made either: {@see suppressesAtRisk()}.
 * - `None`: no leave touches the week.
 */
final readonly class LoaCover
{
    /**
     * Private: the named constructors are the only way in, so a `Covered` with no type or a `NeedsReview`
     * with dates cannot be built.
     */
    private function __construct(
        public LoaCoverageState $state,
        public ?LoaType $type = null,
        public ?CarbonImmutable $startsAt = null,
        public ?CarbonImmutable $endsAt = null,
        public ?string $sourceThread = null,
        public ?string $reviewReason = null,
        public ?CarbonImmutable $expectedReturnAt = null,
        public bool $overdue = false,
        public ?int $endsInDays = null,
        public bool $ended = false,
    ) {}

    public static function none(): self
    {
        return new self(LoaCoverageState::None);
    }

    /**
     * `$today` is the server's UTC day (AD-11), or null when the week asked about does not contain it. An
     * ELOA still open after its expected return date is overdue: it runs until a return is posted, so it is
     * flagged for a leader to chase, never ended. A leave that ends today or later says in how many days
     * (`endsInDays`), for the "LOA, ends in 2d" pill; one that ended earlier in the week has none. Both are
     * statements about today, so with no `$today` (a past or future week) they are `false` and null.
     * `$asOf` is the reference day for `ended` (default `$today`): the leave's last day is before it, so a screen
     * says "ended", not "ends", for a leave already over. It holds for any week asked about.
     */
    public static function covered(Loa $loa, ?CarbonInterface $today = null, ?CarbonInterface $asOf = null): self
    {
        $expected = $loa->expected_return_at ? CarbonImmutable::instance($loa->expected_return_at) : null;
        $ends = $loa->ends_at ? CarbonImmutable::instance($loa->ends_at) : null;
        $endsInDays = $today !== null && $ends !== null && $ends->toDateString() >= $today->toDateString()
            ? (int) CarbonImmutable::parse($today->toDateString(), 'UTC')->diffInDays($ends->startOfDay())
            : null;

        $asOf ??= $today;

        return new self(
            LoaCoverageState::Covered,
            $loa->type,
            $loa->starts_at ? CarbonImmutable::instance($loa->starts_at) : null,
            $ends,
            $loa->source_thread,
            expectedReturnAt: $expected,
            overdue: $today !== null && $loa->ends_at === null && $expected !== null && $expected->toDateString() < $today->toDateString(),
            endsInDays: $endsInDays,
            ended: $asOf !== null && $ends !== null && $ends->toDateString() < $asOf->toDateString(),
        );
    }

    /**
     * A leave that asserts nothing: the row's dates are deliberately not carried over, since the parser
     * never established them.
     */
    public static function needsReview(Loa $loa): self
    {
        return new self(
            LoaCoverageState::NeedsReview,
            $loa->type,
            sourceThread: $loa->source_thread,
            reviewReason: $loa->review_reason,
        );
    }

    /** The trooper is on a cleanly read leave this week: their absence is excused. */
    public function isCovered(): bool
    {
        return $this->state === LoaCoverageState::Covered;
    }

    public function needsReviewState(): bool
    {
        return $this->state === LoaCoverageState::NeedsReview;
    }

    /** Covered by an open-ended leave: an ELOA with no return yet. */
    public function ongoing(): bool
    {
        return $this->isCovered() && $this->endsAt === null;
    }

    /** True for both a covering leave and one that needs review: neither may count the trooper as missing. */
    public function suppressesAtRisk(): bool
    {
        return $this->state !== LoaCoverageState::None;
    }

    /**
     * The Inertia prop shape the badge reads. Dates are `Y-m-d` calendar days; a needs-review cover has none.
     * `expectedReturnAt` is an ELOA report's expected return: shown, but it never ends the leave.
     *
     * @return array{state: string, type: ?string, typeLabel: ?string, startsAt: ?string, endsAt: ?string, expectedReturnAt: ?string, ongoing: bool, overdue: bool, endsInDays: ?int, ended: bool, sourceThread: ?string, reviewReason: ?string}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'type' => $this->type?->value,
            'typeLabel' => $this->type?->label(),
            'startsAt' => $this->startsAt?->toDateString(),
            'endsAt' => $this->endsAt?->toDateString(),
            'expectedReturnAt' => $this->expectedReturnAt?->toDateString(),
            'ongoing' => $this->ongoing(),
            'overdue' => $this->overdue,
            'endsInDays' => $this->endsInDays,
            'ended' => $this->ended,
            'sourceThread' => $this->sourceThread,
            'reviewReason' => $this->reviewReason,
        ];
    }
}
