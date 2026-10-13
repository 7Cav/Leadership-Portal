<?php

namespace App\Commands\Troopers;

/**
 * Place a trooper the roster sync could not map into a section, by hand (2.7). The reason is the
 * Admin's own words for why: a placement is a personnel action, so it is required, and the audit
 * entry keeps it. Handled by {@see PlaceTrooperInSectionHandler}.
 */
final readonly class PlaceTrooperInSection
{
    public function __construct(
        public int $trooperId,
        public int $sectionId,
        public string $reason,
    ) {}
}
