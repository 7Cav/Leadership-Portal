<?php

namespace App\Commands\Units;

/**
 * Make a serving trooper the acting lead of a unit the Cav roster names no lead for (decided 2026-10-25), with the
 * Admin's reason. Handled by {@see AssignActingLeadHandler}.
 */
final readonly class AssignActingLead
{
    public function __construct(
        public int $unitId,
        public int $trooperId,
        public string $reason,
    ) {}
}
