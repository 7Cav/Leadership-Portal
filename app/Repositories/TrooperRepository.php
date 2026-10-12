<?php

namespace App\Repositories;

use App\Models\Trooper;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
     * `$unreadableCavIds` are troopers Cav listed but whose row could not be parsed: they stay
     * active (they are in `$activeCavIds`) but their billet is cleared in the same transaction —
     * their current billet is unknown, and a stale one must not keep conferring command scope
     * (1.16, deny-by-default).
     *
     * @param  list<array{cav_id: int, rank: ?string, rank_short?: ?string, username?: ?string, forum_user_id?: ?int, billet?: ?string, mos: ?string, tis: mixed, tig: mixed, status: string, cav_position_id?: ?int}>  $rows
     * @param  list<int>  $activeCavIds
     * @param  list<int>  $unreadableCavIds
     * @return array{inserted: int, updated: int, inactivated: int}
     */
    public function sync(array $rows, array $activeCavIds, array $unreadableCavIds = []): array;

    /**
     * Upsert every row keyed by `cav_id`, updating an existing trooper's synced columns in place
     * (their internal `id` PK never changes) or inserting a new one. Never touches
     * `primary_section_id` — that column belongs to 2.5/2.7, not the sync handler.
     *
     * `cav_position_id` (the primary billet, 1.16) is overwritten like any synced column, null
     * included: a billet Cav no longer reports must not keep conferring command scope. The same
     * goes for the forum identity and short rank (`username`, `forum_user_id`, `rank_short`,
     * 2.10), so a forum rename follows, and the billet title (`billet`, 2.8). A row without one of these optional keys writes null.
     *
     * @param  list<array{cav_id: int, rank: ?string, rank_short?: ?string, username?: ?string, forum_user_id?: ?int, billet?: ?string, mos: ?string, tis: mixed, tig: mixed, status: string, cav_position_id?: ?int}>  $rows
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

    /**
     * The troopers currently assigned within `$unit`'s subtree (as `assignedWithin()`), as models
     * ordered by id — a report's roster, rendered through `display_name`.
     *
     * @return Collection<int, Trooper>
     */
    public function rosterOf(Unit $unit): Collection;

    /**
     * The cached troopers with these `cav_id`s, keyed by `cav_id` — how a portal user is shown by
     * their roster name (e.g. a report's sender). Unknown ids are simply absent.
     *
     * @param  list<int>  $cavIds
     * @return Collection<int, Trooper>
     */
    public function byCavId(array $cavIds): Collection;

    /**
     * The synced rank of each trooper in `$cavIds`, keyed by `cav_id` — the label the view-as
     * picker and banner put in front of a username (Story 1.13). A trooper with no cached row,
     * or no rank, is simply absent.
     *
     * @param  list<int>  $cavIds
     * @return Collection<int, string>
     */
    public function ranksByCavId(array $cavIds): Collection;

    /**
     * The same population as {@see assignedWithin()}, each trooper with the section they are
     * placed in: `trooper_id => primary_section_id`, ascending by trooper — so one query can split
     * a whole subtree's troopers by section (5.8's batched roll-up).
     *
     * @return Collection<int, int>
     */
    public function sectionsOfAssignedWithin(Unit $unit): Collection;

    /**
     * Every trooper placed within `$unit`'s subtree — departed (`inactive`) troopers included, so
     * a roster can show them as departed rather than silently drop them (2.8, AD-21) — ordered by
     * id. One query, however large the subtree. Unplaced troopers (no `primary_section_id`) never
     * appear: they are surfaced for placement elsewhere (AD-18).
     *
     * @return EloquentCollection<int, Trooper>
     */
    public function placedWithin(Unit $unit): EloquentCollection;

    public function find(int $id): ?Trooper;
}
