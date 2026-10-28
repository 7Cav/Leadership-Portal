<?php

namespace App\Repositories;

use App\Models\CommandRole;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitCommandMapping;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Sole DB access for the leadership billet → commanded unit mapping (AD-2, 1.16). The org-structure
 * import (2.9) is its only writer and reconciles it to exactly what Cav publishes on every run —
 * unlike the section mapping, there is no hand curation to protect, and a stale row would keep
 * granting scope. The roster-derived half of the user's scope (AD-7, AD-8) reads it through
 * {@see commandedUnitFor()}.
 */
interface UnitCommandMappingRepository
{
    /**
     * Every mapping as `cav_position_id => unit_id`, for the import to diff against Cav.
     *
     * @return Collection<int, int>
     */
    public function unitIdsByPositionId(): Collection;

    public function create(int $unitId, int $cavPositionId): UnitCommandMapping;

    /**
     * The ids of every unit some billet commands, held or not: with {@see commandersOf()}, a unit here with no serving
     * holder has a vacant lead (2026-10-24). A unit no billet commands has no lead to be vacant.
     *
     * @return Collection<int, int>
     */
    public function commandedUnitIds(): Collection;

    /**
     * Point an existing billet's mapping at a different unit (a Cav reorg).
     */
    public function repoint(int $cavPositionId, int $unitId): void;

    /**
     * Delete every mapping whose billet is not in `$cavPositionIds` — a billet Cav no longer
     * lists as a command must stop conferring scope. Returns the number of rows removed.
     *
     * @param  list<int>  $cavPositionIds
     */
    public function deleteExcept(array $cavPositionIds): int;

    /**
     * The unit `$user`'s synced primary billet commands, or null — one joined, indexed query:
     * `users.cav_id` → `troopers.cav_id` (unique) → `troopers.cav_position_id` →
     * `unit_command_mappings.cav_position_id` (unique) → `units`. Null when the user has no
     * `cav_id`, no synced trooper, or a billet with no command mapping, and unless the trooper
     * stands on a serving roster (combat, reserve, ELOA) — an allow-list, so a departed
     * (`inactive`) trooper's last billet, or any standing added later, confers nothing.
     */
    public function commandedUnitFor(User $user): ?Unit;

    /**
     * {@see commandedUnitFor()} for many users at once, keyed by `cav_id` — one joined query for
     * the view-as picker's people list (Story 1.13), with the same serving-roster allow-list. A
     * cav id with no commanded unit is absent.
     *
     * @param  list<int>  $cavIds
     * @return Collection<int, Unit>
     */
    public function commandedUnitsByCavId(array $cavIds): Collection;

    /**
     * The unit each of `$trooperIds` holds a command billet of, keyed by trooper id, with the same serving-roster
     * allow-list: what the roster's billet tag (#443) is read from. A trooper whose billet commands nothing is absent.
     * One query.
     *
     * @param  list<int>  $trooperIds
     * @return Collection<int, Unit>
     */
    public function commandedUnitsByTrooperId(array $trooperIds): Collection;

    /**
     * The serving trooper holding each unit's commanding billet, keyed by unit id — the leader a
     * roster's unit headers and the org page name (2.8): the CO of a company, battalion or the
     * regiment, the PL of a platoon, the SL of a section ({@see CommandRole::leadOf()}). The rest of
     * the command team maps to the unit too (XO, 1SG, PSG, ASL, SGM, CSM) but never leads it, so with
     * the commanding billet vacant the unit is absent and shows its lead as vacant (#444). Their scope
     * is untouched: {@see commandedUnitFor()} still gives each of them the unit. A staff portfolio's
     * officer, the one billet mapped to their group, leads it. A unit with no mapping, or whose
     * billet no serving trooper holds, is absent. Two queries for any number of units.
     *
     * @param  list<int>  $unitIds
     * @return Collection<int, Trooper>
     */
    public function commandersOf(array $unitIds): Collection;

    /**
     * Every serving trooper whose billet maps to each of `$unitIds`, keyed by unit id, each list in trooper id order:
     * the unit's whole command team (CO, XO and 1SG; PL and PSG; SL and ASL), with the same serving-roster allow-list.
     * What {@see commandersOf()} picks the lead from, and what act as reads to tell whether a leader has anyone junior
     * in their own team. A unit no serving trooper's billet maps to is absent. One query.
     *
     * @param  list<int>  $unitIds
     * @return Collection<int, Collection<int, Trooper>>
     */
    public function teamsOf(array $unitIds): Collection;
}
