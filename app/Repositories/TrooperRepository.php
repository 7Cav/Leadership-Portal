<?php

namespace App\Repositories;

use App\Models\Trooper;
use App\Models\TrooperStatus;
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
     * @param  list<int>|null  $withinSectionIds  an echelon-scoped sync (departures only among troopers placed in these sections); null is the whole roster
     * @return array{inserted: int, updated: int, inactivated: int}
     */
    public function sync(array $rows, array $activeCavIds, array $unreadableCavIds = [], ?array $withinSectionIds = null): array;

    /**
     * Upsert every row keyed by `cav_id`, updating an existing trooper's synced columns in place
     * (their internal `id` PK never changes) or inserting a new one. Never touches
     * `primary_section_id` — that column belongs to 2.5/2.7, not the sync handler. `updated`
     * counts only existing troopers whose synced columns actually changed.
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
     * With `$withinSectionIds` only troopers placed in those sections are considered, so an echelon-scoped
     * sync never departs anyone outside its echelon.
     *
     * @param  list<int>  $activeCavIds
     * @param  list<int>|null  $withinSectionIds
     */
    public function markDepartedExcept(array $activeCavIds, ?array $withinSectionIds = null): int;

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
     * concurrent placements cannot both win. The returned model is the post-update row, and what the
     * audit envelope's digest is computed from.
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
     * {@see idsPlacedIn()} narrowed to troopers still serving (`TrooperStatus::serving()`). A departed
     * trooper keeps their last `primary_section_id`, so capture (7.6) reads this one: a player still
     * on the server after leaving the Cav must not be auto-marked. Manual marks and report rows keep
     * {@see idsPlacedIn()}, which does not look at standing.
     *
     * @param  list<int>  $trooperIds
     * @return Collection<int, int>
     */
    public function servingIdsPlacedIn(int $sectionId, array $trooperIds): Collection;

    /**
     * The ids of the troopers currently assigned within `$unit`'s subtree — primary section
     * anywhere under it, not inactive (LOA still counts) — ascending. Strength's population (5.1).
     *
     * @return Collection<int, int>
     */
    public function assignedWithin(Unit $unit): Collection;

    /**
     * The first `$limit` active troopers the sync could not place into a section (`primary_section_id`
     * null), as plain rows for the Settings panel that surfaces them for manual placement (2.6).
     * Departed troopers are left out: nobody needs to place someone no longer on a Cav roster.
     * Ordered by username (troopers with no username last), then `cav_id`, so the list never
     * reshuffles between requests. Reads the cache, so a failed last sync still shows the last good
     * set. Pair with `unplacedCount()` for the true total when the list is capped.
     *
     * @return Collection<int, array{id: int, cav_id: int, username: ?string, rank: ?string, mos: ?string, billet: ?string, position_id: ?int}>
     */
    public function unplaced(int $limit): Collection;

    /**
     * How many troopers `unplaced()` would return without a limit.
     */
    public function unplacedCount(): int;

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
     * The cached roster standing of the trooper at `$cavId`, or null when the cache holds no such
     * trooper. Read from the local cache only, never a live Cav call — the sign-in gate leans on
     * this precisely when Cav is unreachable, and a user's grants are re-checked against it on
     * every scope read.
     */
    public function statusOf(int $cavId): ?TrooperStatus;

    /**
     * Which of `$cavIds` belong to a cached trooper whose standing is serving
     * (`TrooperStatus::serving()`) — one query for a whole listing, such as the staff grants page
     * marking each holder who has left. Ids with no cached trooper, or a departed one, are absent.
     *
     * @param  list<int>  $cavIds
     * @return Collection<int, int>
     */
    public function servingCavIds(array $cavIds): Collection;

    /**
     * The troopers whose username is exactly each of `$usernames`, compared whole and ignoring case, keyed
     * by the lower-cased username. Never a prefix match: `Perry.J` and `Perry.JW` are different people.
     * A username with no trooper is simply absent, and one with more than one (which the Cav does not
     * allow, but the cache does not enforce) lists them all, so the caller can refuse to guess.
     *
     * @param  list<string>  $usernames
     * @return array<string, list<int>> lower-cased username => trooper ids
     */
    public function idsByUsername(array $usernames): array;

    /**
     * The troopers carrying each of these forum user ids (2.10's cached forum identity), keyed by forum user
     * id. An id with no trooper is absent; one with more than one lists them all, so the caller can refuse
     * to guess.
     *
     * @param  list<int>  $forumUserIds
     * @return array<int, list<int>> forum user id => trooper ids
     */
    public function idsByForumUserId(array $forumUserIds): array;

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

    /**
     * The troopers currently assigned within `$unit`'s subtree: the same set as {@see self::assignedWithin()}
     * (primary section anywhere under it, not `Inactive`; LOA and ELOA still count), as models in id order, so
     * "who counts as active" has one named home next to the query and no departed row is loaded to be dropped.
     *
     * @return EloquentCollection<int, Trooper>
     */
    public function activePlacedWithin(Unit $unit): EloquentCollection;

    public function find(int $id): ?Trooper;

    /**
     * The troopers with these ids, keyed by id; an unknown id is simply absent. One query, for a
     * worklist that names a few troopers out of a whole roster (5.10).
     *
     * @param  list<int>  $ids
     * @return Collection<int, Trooper>
     */
    public function byIds(array $ids): Collection;

    /**
     * The troopers currently placed in the section — `primary_section_id` is the section, not
     * inactive (LOA still counts) — in id order. The cache holds only the current placement, so this is
     * "placed now": a trooper appears in exactly one section's list (AD-14).
     *
     * @return EloquentCollection<int, Trooper>
     */
    public function placedIn(int $sectionId): EloquentCollection;

    /**
     * The troopers currently placed in any of `$sectionIds` — `primary_section_id` is one of them,
     * not inactive (LOA still counts) — ordered by id, in one query however many sections. A
     * scope-wide worklist (7.2); the caller passes the viewer's scope, so scope stays the
     * ScopeResolver's to decide (AD-8).
     *
     * @param  iterable<int>  $sectionIds
     * @return EloquentCollection<int, Trooper>
     */
    public function placedInAny(iterable $sectionIds): EloquentCollection;

    /**
     * The `cav_id`s of every trooper placed in any of these sections, departed ones included: a trooper who left
     * and has since come back to a roster is still this echelon's to reactivate.
     *
     * @param  list<int>  $sectionIds
     * @return list<int>
     */
    public function cavIdsPlacedIn(array $sectionIds): array;

    /**
     * How many cached troopers holding `$status` are missing from `$activeCavIds`, i.e. how many
     * of them {@see markDepartedExcept()} would flip to inactive. The sync handler asks this
     * before believing an empty serving roster: an empty roster that would depart a crowd reads
     * as a broken response, not a real exodus.
     *
     * @param  list<int>  $activeCavIds
     */
    public function countDepartingWithStatus(TrooperStatus $status, array $activeCavIds): int;
}
