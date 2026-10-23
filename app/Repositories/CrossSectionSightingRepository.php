<?php

namespace App\Repositories;

use App\Models\SectionPractice;
use DateTimeInterface;

/**
 * Sole DB access for `cross_section_sightings` (AD-2): every time a capture saw a linked trooper at
 * a practice of a section that is not their own, kept for the later Joint Service Achievement Medal
 * candidates. Nothing writes it yet: the capture stopped recording sightings until practice time
 * slots exist, since its window is the whole Cav week (2026-10-23). Not a cache: a re-run never replaces or
 * removes what an earlier run recorded.
 */
interface CrossSectionSightingRepository
{
    /**
     * Record that each of `$trooperIds` was seen at `$hostPractice` during the capture window, once
     * per (trooper, host practice): a pair already recorded is left exactly as it is (the first
     * sighting wins), so re-running a capture adds nothing. Insert-or-find under the UNIQUE key, so
     * two runs racing on the same practice land on one row. Returns how many rows were added.
     *
     * @param  list<int>  $trooperIds
     */
    public function record(SectionPractice $hostPractice, array $trooperIds, DateTimeInterface $seenFrom, DateTimeInterface $seenUntil): int;
}
