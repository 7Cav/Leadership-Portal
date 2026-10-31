<?php

namespace App\Support\Time;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The single time → `week_start` mapping (AD-11). The Cav week runs from Saturday 00:00 UTC up
 * to (not including) the next Saturday 00:00 UTC, one fixed portal-wide boundary — never a
 * per-unit timezone. Rollcall, attendance bucketing and every roll-up must derive `week_start`
 * here and nowhere else, so a leaf and its parent can never disagree on which week a moment
 * belongs to.
 *
 * Display formatting (the portal's `30MAY26`) is a presentation concern, not this helper's; the one server-side week\n * label is {@see WeekLabel::range()}.
 */
final class WeekOf
{
    /**
     * The Saturday 00:00 UTC that opens the Cav week containing `$moment`. The input is
     * normalized to UTC before bucketing, so an offset-bearing time lands in the week of its
     * UTC instant, not of its wall-clock date. The input is never mutated.
     */
    public static function start(DateTimeInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)
            ->utc()
            ->startOfWeek(CarbonInterface::SATURDAY);
    }

    /**
     * The start of the Cav week before the one opening at `$weekStart`.
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public static function previous(CarbonInterface $weekStart): CarbonImmutable
    {
        return self::requireStart($weekStart)->subWeek();
    }

    /**
     * The start of the Cav week `$weeks` weeks after the one opening at `$weekStart` (the next one by default),
     * which is also the first instant after a range of `$weeks` weeks that begins at `$weekStart`.
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public static function next(CarbonInterface $weekStart, int $weeks = 1): CarbonImmutable
    {
        return self::requireStart($weekStart)->addWeeks($weeks);
    }

    /**
     * The week whose report cycle is running at `$now`. A week's report falls due at 2359Z on the
     * Saturday after it ends — day one of the next week — so until that deadline passes the
     * closing week is still the current one, not the fresh week that would hide a report due in a
     * few hours.
     */
    public static function reporting(DateTimeInterface $now): CarbonImmutable
    {
        $moment = CarbonImmutable::instance($now)->utc();
        $weekStart = self::start($moment);

        return $moment->lessThan(self::reportDue($weekStart->subWeek())) ? $weekStart->subWeek() : $weekStart;
    }

    /**
     * The last Cav week to have ended at `$now`: the one before the week containing it. What a staff report opens on,
     * since one is sent only once its week is over.
     */
    public static function lastEnded(DateTimeInterface $now): CarbonImmutable
    {
        return self::start($now)->subWeek();
    }

    /**
     * Whether the week opening at `$weekStart` is over at `$now`: its Friday has passed.
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public static function hasEnded(CarbonInterface $weekStart, DateTimeInterface $now): bool
    {
        return self::next($weekStart)->lessThanOrEqualTo($now);
    }

    /**
     * When the report for the week opening at `$weekStart` falls due: 2359Z on the Saturday after
     * the week ends.
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public static function reportDue(CarbonInterface $weekStart): CarbonImmutable
    {
        return self::requireStart($weekStart)->addWeek()->setTime(23, 59);
    }

    /**
     * The oldest week a write may touch: the week `portal.week_floor_weeks` Cav weeks before the
     * one containing `$now` (the current moment by default). The rollcall, the weekly report and
     * the Commands behind them refuse a week before it, so nobody can create a practice or a
     * report for a week long before the portal existed.
     */
    public static function floor(?DateTimeInterface $now = null): CarbonImmutable
    {
        return self::start($now ?? CarbonImmutable::now())->subWeeks(max(0, (int) config('portal.week_floor_weeks')));
    }

    /**
     * Whether the week containing `$moment` is older than {@see floor()}.
     */
    public static function isBeforeFloor(DateTimeInterface $moment, ?DateTimeInterface $now = null): bool
    {
        return self::start($moment)->lessThan(self::floor($now));
    }

    /**
     * The Cav weeks of a month (#380): every week whose Saturday 00:00 UTC opener falls in `$month` (`YYYY-MM`),
     * earliest first. Four or five of them, and each week belongs to exactly one month this way, so a monthly
     * training plan never shares a week with the next one.
     *
     * @return list<CarbonImmutable>
     *
     * @throws InvalidArgumentException when `$month` is not a `YYYY-MM` month.
     */
    public static function inMonth(string $month): array
    {
        $first = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', "{$month}-01", 'UTC')
            : false;

        if ($first === false) {
            throw new InvalidArgumentException("A month is YYYY-MM; got {$month}.");
        }

        $weeks = [];
        for ($saturday = $first->isSaturday() ? $first : $first->next(CarbonInterface::SATURDAY); $saturday->month === $first->month; $saturday = $saturday->addWeek()) {
            $weeks[] = $saturday;
        }

        return $weeks;
    }

    /**
     * `$weekStart` itself, as its UTC instant, when it already is a week boundary — the guard for
     * a caller *handed* a `week_start` rather than deriving one. Validates rather than derives, so
     * a wrong date is refused instead of silently snapped to some other week.
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public static function requireStart(CarbonInterface $weekStart): CarbonImmutable
    {
        $boundary = self::start($weekStart);

        if (! $boundary->equalTo($weekStart)) {
            throw new InvalidArgumentException(
                "week_start must be a Saturday 00:00 UTC (AD-11); got {$weekStart->toIso8601String()}.",
            );
        }

        return $boundary;
    }
}
