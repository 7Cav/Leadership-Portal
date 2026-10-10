<?php

namespace App\Commands\Attendance;

/**
 * A batch set a practice status for a trooper whose leave covers the week (Story 3.4). It is an
 * {@see InvalidAttendanceMarkException}, so a caller that only wants the refusal catches that, and it also carries the
 * troopers it was about so the rollcall screen can name them rather than show ids.
 */
class LeaveCoversWeekException extends InvalidAttendanceMarkException
{
    /**
     * @param  list<int>  $trooperIds
     */
    public function __construct(public readonly array $trooperIds)
    {
        parent::__construct(sprintf(
            'Troopers %s are on leave for this week, so their practice mark is derived from the LOA data and cannot be set by hand.',
            implode(', ', $trooperIds),
        ));
    }
}
