<?php

namespace App\Commands\Units;

/**
 * Remove a unit's acting lead (decided 2026-10-25), with the Admin's reason. Handled by {@see RemoveActingLeadHandler}.
 */
final readonly class RemoveActingLead
{
    public function __construct(
        public int $unitId,
        public string $reason,
    ) {}
}
