<?php

namespace App\Commands\ReportEdits;

use DateTimeImmutable;

/**
 * Ask to edit a report that locked at its deadline, with a reason: a unit's weekly report (`$unitId`, `$trooperId`
 * null) or the actor's own staff report (`$trooperId`, `$unitId` null). Handled by {@see RequestReportEditHandler}.
 */
final readonly class RequestReportEdit
{
    public function __construct(
        public ?int $unitId,
        public ?int $trooperId,
        public DateTimeImmutable $week,
        public ?string $reason,
    ) {}

    public static function weekly(int $unitId, DateTimeImmutable $week, ?string $reason): self
    {
        return new self($unitId, null, $week, $reason);
    }

    public static function staff(int $trooperId, DateTimeImmutable $week, ?string $reason): self
    {
        return new self(null, $trooperId, $week, $reason);
    }
}
