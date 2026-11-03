<?php

namespace App\Repositories;

use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * When a sent report may still be edited: until it locks at its deadline, 2359Z on the Saturday after the week (`WeekOf::reportDue()`). Past
 * that instant a sent report is frozen for good (AD-12), as it always was.
 *
 * A sent weekly report is open for editing only once someone reopens it, and only until that deadline: an open report
 * is still sent (its snapshot stays the record and roll-ups keep reading it) but no longer locks its week's rollcall,
 * so the leader can fix the marks and send again. A reopened report nobody sends again locks at the deadline with the
 * snapshot it had, as if it was never reopened.
 */
final class ReportEditWindow
{
    /** Whether a report for the week opening at `$weekStart` can still be edited at `$now` (default: now). */
    public static function isOpen(CarbonInterface $weekStart, ?CarbonInterface $now = null): bool
    {
        return ($now ?? CarbonImmutable::now('UTC'))->lessThanOrEqualTo(WeekOf::reportDue($weekStart));
    }

    /** Whether the sent weekly report is reopened and still inside its edit window: the rollcall it froze is open. */
    public static function isEditing(?WeeklyReport $report, ?CarbonInterface $now = null): bool
    {
        return $report !== null
            && $report->status === WeeklyReportStatus::Sent
            && $report->reopened_at !== null
            && self::isOpen($report->week_start, $now);
    }

    /** Whether the weekly report is sent and frozen: sent, and not reopened inside its window. */
    public static function isFrozen(?WeeklyReport $report, ?CarbonInterface $now = null): bool
    {
        return $report !== null && $report->status === WeeklyReportStatus::Sent && ! self::isEditing($report, $now);
    }
}
