<?php

namespace App\Kpi;

use App\Models\Unit;
use App\Models\WeeklyReport;
use Carbon\CarbonInterface;
use RuntimeException;
use Throwable;

/**
 * A unit's weekly-report KPIs cannot be produced, so its report cannot be frozen. Thrown before
 * anything is written: a non-leaf unit with no children to roll up, a prior-week sent snapshot
 * that cannot be read, so no delta can be computed against it, or a child's sent snapshot that
 * cannot be read, so there is nothing true to sum.
 */
class ReportKpisUnavailableException extends RuntimeException
{
    public function __construct(
        string $message,
        ?Throwable $previous = null,
        /** The child's sent report whose stored snapshot does not read back, when that is the reason. */
        public readonly ?WeeklyReport $unreadable = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function noChildren(Unit $unit): self
    {
        return new self(
            "Unit {$unit->id} is a {$unit->type->value} with no units under it, so it has no reports to roll up.",
        );
    }

    public static function unreadablePrior(Unit $unit, CarbonInterface $priorWeek, Throwable $previous): self
    {
        return new self(
            "Unit {$unit->id}'s sent report for the week of {$priorWeek->toDateString()} cannot be read, so no delta can be computed against it: {$previous->getMessage()}",
            $previous,
        );
    }

    /** A sent report whose stored snapshot does not read back: a corrupt record, never a zero. */
    public static function unreadableSnapshot(WeeklyReport $report, Throwable $previous): self
    {
        return new self(
            "The sent report of unit {$report->unit_id} for {$report->week_start->toDateString()} has an unreadable snapshot: {$previous->getMessage()}",
            $previous,
            $report,
        );
    }
}
