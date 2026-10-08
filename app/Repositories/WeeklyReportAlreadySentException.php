<?php

namespace App\Repositories;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * A write was refused because the unit-week's report is already sent, and a sent snapshot is
 * frozen (AD-12). Its own type so 5.6's send Command can tell "already sent" — the idempotent
 * outcome of a repeat send — apart from a real failure, with the unit and week it refused.
 */
class WeeklyReportAlreadySentException extends RuntimeException
{
    public function __construct(
        public readonly int $unitId,
        public readonly CarbonImmutable $weekStart,
    ) {
        parent::__construct(
            "The weekly report for unit {$unitId}, week of {$weekStart->toDateString()}, is already sent; a sent snapshot is frozen (AD-12).",
        );
    }

    public static function for(int $unitId, CarbonInterface $weekStart): self
    {
        return new self($unitId, CarbonImmutable::instance($weekStart));
    }
}
