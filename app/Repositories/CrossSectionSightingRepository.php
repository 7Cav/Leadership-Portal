<?php

namespace App\Repositories;

use App\Models\CrossSectionSighting;
use App\Models\SectionPractice;
use App\Models\Unit;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Sole DB access for `cross_section_sightings` (AD-2): every time a linked trooper attended a practice of a section
 * that is not their own, kept as the Joint Service Achievement Medal evidence. Two writers, one per source: the audited
 * guest Commands ({@see recordManual()} and {@see removeManual()}, what the host section's leader said) and the capture
 * ({@see record()}, what the game server saw). The capture stopped recording sightings until practice time slots
 * exist, since its window is the whole Cav week (2026-10-23), so for now only the guest Commands write. Not a cache: a
 * capture re-run never replaces or removes what an earlier run recorded, and a capture sighting is never removed by
 * hand.
 */
interface CrossSectionSightingRepository
{
    /**
     * Record that each of `$trooperIds` was seen at `$hostPractice` during the capture window, once
     * per (trooper, host practice): a capture sighting already recorded is left exactly as it is (the
     * first sighting wins), so re-running a capture adds nothing. A leader's hand entry for the pair
     * becomes a capture sighting with this window, keeping its `recorded_by`: what the server saw is
     * evidence no leader can take back, so it can no longer be removed by hand. Insert-or-find under
     * the UNIQUE key, so two runs racing on the same practice land on one row. Returns how many rows
     * were added (an upgraded hand entry is not one).
     *
     * @param  list<int>  $trooperIds
     */
    public function record(SectionPractice $hostPractice, array $trooperIds, DateTimeInterface $seenFrom, DateTimeInterface $seenUntil): int;

    /**
     * The host leader's word that `$trooperId` attended `$hostPractice` (source `manual`, no capture window, recorded by
     * `$recordedBy`). Insert-or-find under the same UNIQUE key as {@see record()}: returns the new row, or null when the
     * pair is already recorded, from either source, which is then left as it is.
     */
    public function recordManual(SectionPractice $hostPractice, int $trooperId, int $recordedBy): ?CrossSectionSighting;

    /**
     * Delete the hand-added entry for `$trooperId` at the host practice. A capture sighting is never deleted here,
     * whoever asks. Returns whether a row was deleted.
     */
    public function removeManual(int $hostPracticeId, int $trooperId): bool;

    public function find(int $hostPracticeId, int $trooperId): ?CrossSectionSighting;

    /**
     * Every trooper recorded at this host practice, capture and manual, with `trooper` and `recordedBy` loaded, by
     * username: the rollcall's "Guests" list.
     *
     * @return Collection<int, CrossSectionSighting>
     */
    public function forHostPractice(int $hostPracticeId): Collection;

    /**
     * Whether `$trooperId` is recorded at any host practice of this Cav week, from either source: what still supports an
     * automatic makeup on their own rollcall once one entry is removed.
     */
    public function existsForTrooperInWeek(int $trooperId, CarbonInterface $weekStart): bool;

    /**
     * Which of `$trooperIds` are still recorded at any host practice of this Cav week, from either source, as a locking
     * read (shared): what lets `MarkAttendanceHandler::preMarkMakeups()` write an
     * automatic makeup only while a sighting supports it. Inside a transaction, the rows found cannot be removed until
     * it ends, and a removal still in flight is waited for, so an Undo can never land between this read and the makeup.
     *
     * @param  list<int>  $trooperIds
     * @return list<int>
     */
    public function trooperIdsSeenInWeek(array $trooperIds, CarbonInterface $weekStart): array;

    /**
     * The guests at `$unit`'s practices over `$firstWeek`..`$lastWeek` (whole Cav weeks, both included): each serving
     * trooper recorded at a practice of a section anywhere under `$unit` whose own primary section is *not* under it,
     * with how many of those practices and the newest week. A departed trooper is left out, and so is an unplaced one
     * (no section of their own to come from). Keyed and ordered by trooper id. One query, whatever the subtree.
     *
     * @return Collection<int, GuestVisits>
     */
    public function visitsWithin(Unit $unit, CarbonInterface $firstWeek, CarbonInterface $lastWeek): Collection;
}
