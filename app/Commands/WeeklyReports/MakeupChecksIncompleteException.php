<?php

namespace App\Commands\WeeklyReports;

use RuntimeException;

/**
 * A section's send does not answer, exactly once, for each trooper who asked for a make-up that week and has none
 * recorded, or answers for a trooper who owes none. Thrown before anything is written, so the report controller can
 * turn it into a form error that asks the leader to answer again.
 */
class MakeupChecksIncompleteException extends RuntimeException
{
    /**
     * @param  list<int>  $unanswered
     * @param  list<int>  $notOwed
     */
    public function __construct(public readonly array $unanswered, public readonly array $notOwed)
    {
        parent::__construct('Say for each trooper who still owes a make-up this week whether they made it up.');
    }
}
