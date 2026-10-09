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
     * 2.10), so a forum rename follows. A row without one of these optional keys writes null.
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
     * A trooper that *is* in the map is set to that section and marked `placement_source = sync`,
     * even over a manual placement: once Cav can resolve a billet, the source of truth wins and the
     * manual placement is retired (2.7). The rule is deterministic, so it cannot flip-flop between runs.
     *
     * @param  array<int, int>  $cavIdToSectionId  cav_id => units.id
     */
    public function updatePrimarySections(array $cavIdToSectionId): int;

    public function find(int $id): ?Trooper;

    /**
     * How many troopers are currently assigned to each section, keyed by section id: not inactive
     * (LOA still counts, as in strength), and only sections that have at least one. The Settings tree
     * rolls these up its branches (2.7), so a placement is visible there at once.
     *
     * @return Collection<int, int>
     */
    public function countsBySection(): Collection;

    /**
     * Place an unplaced trooper into `$sectionId` by hand (2.7), marking `placement_source = manual`.
     * Touches only the two placement columns, never a synced roster field. One conditional write: it
     * applies only while the trooper is still unplaced and still on a roster, and returns the placed
     * trooper, or null when it did not apply (already placed, departed, or no such trooper), so two
     * concurrent placements cannot both win.
     */
    public function place(int $trooperId, int $sectionId): ?Trooper;

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
     * Every active trooper the sync could not place into a section (`primary_section_id` null), for the
     * Settings panel that surfaces them for manual placement (2.6). Departed troopers are left out:
     * nobody needs to place someone no longer on a Cav roster. Ordered by username (troopers with no
     * username last), then `cav_id`, so the list never reshuffles between requests. Reads the cache,
     * so a failed last sync still shows the last good set.
     *
     * @return EloquentCollection<int, Trooper>
     */
    public function unplaced(): EloquentCollection;

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
}
