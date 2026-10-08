<?php

namespace App\Repositories;

use App\Models\Unit;
use Illuminate\Support\Collection;

/**
 * Sole DB access for the cached roster (AD-2, AD-4). `Sync\SyncRosterHandler` is the only caller
 * of the writes here — this class enforces the upsert/never-delete shape AD-4 requires, but it
 * is the handler's job to decide *which* rows are active.
 */
interface TrooperRepository
{
    /**
     * Upsert the active rows and mark everyone else departed as one transaction, so a crash
     * between the two halves can never leave the cache in a state where some troopers were
     * refreshed but departures were not yet applied (or vice versa) — a torn write the sync
     * handler's own "no partial wipe" contract would otherwise only hold for a *fetch* failure,
     * not a write-time one. Returns the row counts the sync handler reports to `sync_runs`
     * (Story 2.3).
     *
     * @param  list<array{cav_id: int, rank: ?string, mos: ?string, tis: mixed, tig: mixed, status: string}>  $rows
     * @param  list<int>  $activeCavIds
     * @return array{inserted: int, updated: int, inactivated: int}
     */
    public function sync(array $rows, array $activeCavIds): array;

    /**
     * Upsert every row keyed by `cav_id`, updating an existing trooper's synced columns in place
     * (their internal `id` PK never changes) or inserting a new one. Never touches
     * `primary_section_id` — that column belongs to 2.5/2.7, not the sync handler.
     *
     * @param  list<array{cav_id: int, rank: ?string, mos: ?string, tis: mixed, tig: mixed, status: string}>  $rows
     * @return array{inserted: int, updated: int}
     */
    public function upsertActive(array $rows): array;

    /**
     * Flip every cached trooper whose `cav_id` is not in `$activeCavIds` to inactive — retained,
     * never deleted, so dependent `attendance`/`weekly_reports` history survives a roster
     * departure (AD-4, AD-5). Already-inactive rows are left alone rather than re-touched.
     * Returns the number of rows actually flipped.
     *
     * @param  list<int>  $activeCavIds
     */
    public function markDepartedExcept(array $activeCavIds): int;

    /**
     * Set `primary_section_id` for exactly the troopers present in `$cavIdToSectionId` (2.5) — a
     * trooper *absent* from the map is left untouched, not nulled: an unmapped trooper's existing
     * `null` stays `null`, and a manually-placed trooper (2.7) whose billet no longer resolves
     * keeps their placement rather than being silently re-nulled by a sync that simply can't
     * resolve them anymore. Returns the number of rows actually updated.
     *
     * @param  array<int, int>  $cavIdToSectionId  cav_id => units.id
     */
    public function updatePrimarySections(array $cavIdToSectionId): int;

    /**
     * Which of `$trooperIds` have `$sectionId` as their primary section — the attendance writer
     * (3.3) marks a trooper only against their own section's practice (AD-14, AD-18). Ids that
     * are placed elsewhere, unplaced, or not a trooper at all are simply absent from the result.
     *
     * @param  list<int>  $trooperIds
     * @return Collection<int, int>
     */
    public function idsPlacedIn(int $sectionId, array $trooperIds): Collection;

    /**
     * The ids of the troopers currently assigned within `$unit`'s subtree — primary section
     * anywhere under it, not inactive (LOA still counts) — ascending. Strength's population (5.1).
     *
     * @return Collection<int, int>
     */
    public function assignedWithin(Unit $unit): Collection;
}
