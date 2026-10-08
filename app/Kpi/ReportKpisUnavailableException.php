<?php

namespace App\Kpi;

use App\Models\Unit;
use RuntimeException;

/**
 * A unit's weekly-report KPIs cannot be produced, so its report cannot be frozen. Thrown before
 * anything is written. Today: a non-leaf unit, whose report is the roll-up of its children's
 * sent snapshots (5.7). 5.7 also throws it while a child is still a draft.
 */
class ReportKpisUnavailableException extends RuntimeException
{
    public static function rollupPending(Unit $unit): self
    {
        return new self(
            "Unit {$unit->id} is a {$unit->type->value}: its report rolls up its children's sent reports, which is not available yet.",
        );
    }
}
