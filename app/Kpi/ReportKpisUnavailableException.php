<?php

namespace App\Kpi;

use App\Models\Unit;
use Carbon\CarbonInterface;
use RuntimeException;
use Throwable;

/**
 * A unit's weekly-report KPIs cannot be produced, so its report cannot be frozen. Thrown before
 * anything is written: a non-leaf unit with no children to roll up, or a prior-week sent
 * snapshot that cannot be read, so no delta can be computed against it.
 */
class ReportKpisUnavailableException extends RuntimeException
{
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
            previous: $previous,
        );
    }
}
