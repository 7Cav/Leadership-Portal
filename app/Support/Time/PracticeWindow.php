<?php

namespace App\Support\Time;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * One section practice's window (#392): a start in Zulu and the hour after it, since a section practice is always one
 * hour. A slot (weekday and start time) is placed in the Cav week of its start (AD-11), so a Friday 2330Z practice
 * belongs to the week it starts in even though it ends in the next, and a Wednesday 2359Z one ends on Thursday.
 */
final readonly class PracticeWindow
{
    /** Every section practice lasts one hour. */
    public const MINUTES = 60;

    private function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    /** The hour from `$start`. */
    public static function startingAt(DateTimeInterface $start): self
    {
        $start = CarbonImmutable::instance($start)->utc();

        return new self($start, $start->addMinutes(self::MINUTES));
    }

    /** A window as it was stored: a captured practice keeps the span it was read for, which may be longer. */
    public static function between(DateTimeInterface $start, DateTimeInterface $end): self
    {
        return new self(CarbonImmutable::instance($start)->utc(), CarbonImmutable::instance($end)->utc());
    }

    /**
     * The slot's window in the week starting `$weekStart`: `$weekday` 0 Sunday to 6 Saturday (Carbon's day numbering) and
     * `$startMinute` minutes after 00:00Z.
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC, or the slot is out of range.
     */
    public static function inWeek(CarbonInterface $weekStart, int $weekday, int $startMinute): self
    {
        self::requireSlot($weekday, $startMinute);
        $weekStart = WeekOf::requireStart($weekStart);

        // The week opens on Saturday: Saturday is day 0 of it, Sunday day 1, and Friday day 6.
        return self::startingAt($weekStart->addDays(($weekday + 1) % 7)->addMinutes($startMinute));
    }

    /** @throws InvalidArgumentException when the weekday or start minute is out of range. */
    public static function requireSlot(int $weekday, int $startMinute): void
    {
        if ($weekday < 0 || $weekday > 6 || $startMinute < 0 || $startMinute > 1439) {
            throw new InvalidArgumentException("A practice slot needs a weekday from 0 to 6 and a start minute from 0 to 1439, got {$weekday} and {$startMinute}.");
        }
    }

    /** The Cav week this practice belongs to: the week of its start. */
    public function weekStart(): CarbonImmutable
    {
        return WeekOf::start($this->start);
    }

    public function hasEnded(DateTimeInterface $now): bool
    {
        return $this->end <= CarbonImmutable::instance($now);
    }
}
