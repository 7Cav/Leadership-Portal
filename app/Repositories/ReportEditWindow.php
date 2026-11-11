<?php

namespace App\Repositories;

use App\Models\ReportEditRequest;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * When a sent report may still be edited: until it locks at its deadline, 2359Z on the Saturday after the week (`WeekOf::reportDue()`). Past
 * that instant a sent report is frozen (AD-12), unless a leader above approved a request to edit it
 * ({@see ReportEditRequest}): its `unlocked_until`, the Saturday 2359Z after the approval, then counts as
 * its deadline too. This holds for a unit's weekly report and a staff report alike.
 *
 * A sent weekly report is open for editing only once someone reopens it, and only until that deadline: an open report
 * is still sent (its snapshot stays the record and roll-ups keep reading it) but no longer locks its week's rollcall,
 * so the leader can fix the marks and send again. A reopened report nobody sends again locks at the deadline with the
 * snapshot it had, as if it was never reopened.
 */
final class ReportEditWindow
{
    /** Whether a report for the week opening at `$weekStart` is still before its deadline at `$now` (default: now). */
    public static function isOpen(CarbonInterface $weekStart, ?CarbonInterface $now = null): bool
    {
        return ($now ?? CarbonImmutable::now('UTC'))->lessThanOrEqualTo(WeekOf::reportDue($weekStart));
    }

    /** Whether a sent report can be edited at `$now`: before its deadline, or before the time an approval unlocked it to. */
    public static function mayEdit(CarbonInterface $weekStart, ?CarbonInterface $unlockedUntil, ?CarbonInterface $now = null): bool
    {
        $now ??= CarbonImmutable::now('UTC');

        return self::isOpen($weekStart, $now) || ($unlockedUntil !== null && $now->lessThanOrEqualTo($unlockedUntil));
    }

    /** Until when a sent report can be edited: its deadline, or the later time an approval unlocked it to. */
    public static function editableUntil(CarbonInterface $weekStart, ?CarbonInterface $unlockedUntil): CarbonImmutable
    {
        $due = WeekOf::reportDue($weekStart);

        return $unlockedUntil !== null && $unlockedUntil->greaterThan($due) ? CarbonImmutable::instance($unlockedUntil) : $due;
    }

    /** Whether the sent report is locked: past {@see editableUntil()}. Only a locked report takes a request to edit it. */
    public static function isLocked(CarbonInterface $weekStart, ?CarbonInterface $unlockedUntil, ?CarbonInterface $now = null): bool
    {
        return ! self::mayEdit($weekStart, $unlockedUntil, $now);
    }

    /**
     * The time an approval at `$now` unlocks a report until: the coming Saturday 2359Z, the next report deadline. On a
     * Saturday before 2359Z that is the same day.
     */
    public static function unlockUntil(?CarbonInterface $now = null): CarbonImmutable
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now('UTC'));
        // The deadline of the week before the current one is 2359Z on the current week's opening Saturday.
        $due = WeekOf::reportDue(WeekOf::start($now)->subWeek());

        return $now->lessThanOrEqualTo($due) ? $due : WeekOf::reportDue(WeekOf::start($now));
    }

    /** Whether the sent weekly report is reopened and still inside its edit window: the rollcall it froze is open. */
    public static function isEditing(?WeeklyReport $report, ?CarbonInterface $now = null): bool
    {
        return $report !== null
            && $report->status === WeeklyReportStatus::Sent
            && $report->reopened_at !== null
            && self::mayEdit($report->week_start, $report->unlocked_until, $now);
    }

    /** Whether the weekly report is sent and frozen: sent, and not reopened inside its window. */
    public static function isFrozen(?WeeklyReport $report, ?CarbonInterface $now = null): bool
    {
        return $report !== null && $report->status === WeeklyReportStatus::Sent && ! self::isEditing($report, $now);
    }
}
