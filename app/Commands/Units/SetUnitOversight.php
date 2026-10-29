<?php

namespace App\Commands\Units;

/**
 * Set or clear the regimental staff officer who oversees a unit (decided by the S6, 2026-10-29), with the Admin's
 * reason: a trooper id sets them, null clears. Handled by {@see SetUnitOversightHandler}.
 */
final readonly class SetUnitOversight
{
    public function __construct(
        public int $unitId,
        public ?int $trooperId,
        public string $reason,
    ) {}
}
