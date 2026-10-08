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
    public function __construct(
        public LoaCoverageState $state,
        public ?LoaType $type = null,
        public ?CarbonImmutable $startsAt = null,
        public ?CarbonImmutable $endsAt = null,
        public ?string $sourceThread = null,
        public ?string $reviewReason = null,
        public ?CarbonImmutable $expectedReturnAt = null,
        public bool $overdue = false,
    ) {}

    public static function none(): self
    {
        return new self(LoaCoverageState::None);
    }

    /**
     * `$today` is the server's UTC day (AD-11). An ELOA still open after its expected return date is overdue:
     * it runs until a return is posted, so it is flagged for a leader to chase, never ended.
     */
    public static function covered(Loa $loa, CarbonInterface $today): self
    {
        $expected = $loa->expected_return_at ? CarbonImmutable::instance($loa->expected_return_at) : null;

        return new self(
            LoaCoverageState::Covered,
            $loa->type,
            $loa->starts_at ? CarbonImmutable::instance($loa->starts_at) : null,
            $loa->ends_at ? CarbonImmutable::instance($loa->ends_at) : null,
            $loa->source_thread,
            expectedReturnAt: $expected,
            overdue: $loa->ends_at === null && $expected !== null && $expected->toDateString() < $today->toDateString(),
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
     * @return array{state: string, type: ?string, typeLabel: ?string, startsAt: ?string, endsAt: ?string, expectedReturnAt: ?string, ongoing: bool, overdue: bool, sourceThread: ?string, reviewReason: ?string}
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
            'sourceThread' => $this->sourceThread,
            'reviewReason' => $this->reviewReason,
        ];
    }
}
