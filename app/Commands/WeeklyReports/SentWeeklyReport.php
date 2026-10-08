<?php

namespace App\Commands\WeeklyReports;

use App\Models\WeeklyReport;

/**
 * The outcome of a send: the unit-week's sent report, and whether *this* send froze it or found
 * it already frozen — by an earlier send or a concurrent one that won the race. `alreadySent` is
 * a normal outcome, not an error: the caller tells the leader "this report was already sent" and
 * shows `$report`, the frozen snapshot, unchanged.
 */
final readonly class SentWeeklyReport
{
    public function __construct(
        public WeeklyReport $report,
        public bool $alreadySent,
    ) {}
}
