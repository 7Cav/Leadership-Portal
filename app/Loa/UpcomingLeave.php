<?php

namespace App\Loa;

use App\Support\Time\WeekOf;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Who is about to go on leave: the troopers whose cleanly read leave starts in the Cav week after `$weekStart`, for
 * the "Leave from 17Oct26" chip a leader plans practice by. Read from the one LOA definition ({@see LoaCoverage},
 * AD-21), never from `loas` directly. A leave that needs review asserts no start, so it never shows here (AD-4); a
 * leave already running in `$weekStart` is not "upcoming" either, since it started earlier.
 */
final class UpcomingLeave
{
    public function __construct(private readonly LoaCoverage $coverage) {}

    /**
     * @param  iterable<int>  $trooperIds
     * @return array<int, string> trooper id => the leave's first day (`Y-m-d`), only for those with one starting next week
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function startingAfter(iterable $trooperIds, CarbonInterface $weekStart): array
    {
        $next = WeekOf::next($weekStart);
        $starts = [];

        foreach ($this->coverage->forTroopers($trooperIds, $next) as $trooperId => $cover) {
            if ($cover->isCovered() && $cover->startsAt !== null && $cover->startsAt->toDateString() >= $next->toDateString()) {
                $starts[$trooperId] = $cover->startsAt->toDateString();
            }
        }

        return $starts;
    }
}
