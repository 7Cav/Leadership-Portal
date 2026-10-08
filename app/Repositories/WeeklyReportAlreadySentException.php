<?php

namespace App\Repositories;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * A write was refused because the unit-week's report is already sent, and a sent snapshot is
 * frozen (AD-12). Its own type so 5.6's send Command can tell "already sent" — the idempotent
 * outcome of a repeat send — apart from a real failure.
 */
class WeeklyReportAlreadySentException extends RuntimeException
{
    public static function for(int $unitId, CarbonInterface $weekStart): self
    {
        return new self(
            "The weekly report for unit {$unitId}, week of {$weekStart->toDateString()}, is already sent; a sent snapshot is frozen (AD-12).",
        );
    }
}
