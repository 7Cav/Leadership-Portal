<?php

namespace App\Kpi;

use App\Models\Unit;
use Carbon\CarbonInterface;
use RuntimeException;
use Throwable;

/**
 * A unit's weekly-report KPIs cannot be produced, so its report cannot be frozen. Thrown before
 * anything is written. Today: a non-leaf unit, whose report is the roll-up of its children's
 * sent snapshots (5.7) — 5.7 also throws it while a child is still a draft — or a prior-week sent
 * snapshot that cannot be read, so no delta can be computed against it.
 */
class ReportKpisUnavailableException extends RuntimeException
{
    public static function rollupPending(Unit $unit): self
    {
        return new self(
            "Unit {$unit->id} is a {$unit->type->value}: its report rolls up its children's sent reports, which is not available yet.",
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
