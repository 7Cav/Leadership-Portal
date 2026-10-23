<?php

namespace App\Kpi;

use App\Models\Trooper;
use Carbon\CarbonImmutable;

/**
 * One guest at a unit's practices, as {@see PracticeGuests} reads them: the trooper (placed in a section outside the
 * unit), how many of the unit's practices they attended in the window and the newest one's Cav week, their own SP over
 * the same window from the one KPI definition (AD-21), and whether that makes them a JSAM candidate.
 */
final readonly class PracticeGuest
{
    public function __construct(
        public Trooper $trooper,
        public int $visits,
        public CarbonImmutable $lastWeek,
        public Ratio $ownSp,
        public bool $candidate,
    ) {}
}
