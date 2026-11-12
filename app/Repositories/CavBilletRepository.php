<?php

namespace App\Repositories;

use App\Models\CavBillet;
use Illuminate\Support\Collection;

/**
 * Sole DB access for the billets the Cav lists (`cav_billets`): the name each carries now, the name its placement was
 * confirmed under, and whether an Admin chose to leave it unplaced. The org import and the roster sync record what they
 * see ({@see observe()}); an Admin's confirmation or choice is written only by the audited Commands in
 * `App\Commands\Units` (`ConfirmBilletName`, `AssignBillet`, `LeaveBilletUnplaced`).
 */
interface CavBilletRepository
{
    /**
     * Record the billets seen now, `position id => [title, group title or null, rule or null]`. A null group or rule
     * means "not known here" (the roster sync sees titles only) and keeps the one stored. A billet seen for the first
     * time gets a row; a placed billet (`$placedPositionIds`) with no confirmed name takes the one seen now, so only a
     * later change reads as a rename. A billet left unplaced is new again once its name or group changes. With
     * `$complete` (the import, which sees every billet the Cav lists) a row for a billet the Cav no longer lists is
     * removed. Names are trimmed, so a stray space is never a rename. Returns how many rows were written or removed.
     *
     * @param  array<int, array{0: string, 1: ?string, 2: ?string}>  $seen
     * @param  list<int>  $placedPositionIds
     */
    public function observe(array $seen, array $placedPositionIds, bool $complete): int;

    /**
     * Every row, keyed by `cav_position_id`.
     *
     * @return Collection<int, CavBillet>
     */
    public function all(): Collection;

    /** The row of the billet, locked inside the caller's transaction; null when there is none. */
    public function lockForPosition(int $positionId): ?CavBillet;

    /** Confirm that the billet's placement follows the name it carries now. */
    public function confirmName(CavBillet $billet): CavBillet;

    /** Leave the billet unplaced: it is not new any more until its name or group changes. */
    public function leaveUnplaced(CavBillet $billet): CavBillet;

    /**
     * Billets that land in two or more units at once:
     *
     *  - `title`: billets of one name (trimmed, any case) whose placements point at different units. The sync places
     *    each holder by their own billet, so each unit gets the holders of the billet placed there.
     *  - `rule`: a billet an Admin placed (`admin`) where the import's rules put it elsewhere (`rule`), or where it
     *    commands another unit (`command`). The sync places its holder by the Admin's placement.
     *
     * Each lists the units, each with the billet that lands there and why. A command billet with no placement of its
     * own, and a rule placement beside a command (a portfolio officer placed on the regiment), are one rule each, not a
     * conflict.
     *
     * @return list<array{kind: string, title: string, units: list<array{unitId: int, positionId: int, source: string}>}>
     */
    public function conflicts(): array;

    /**
     * How many billets wait for an Admin: placed billets the Cav renamed since their placement was confirmed, new
     * billets nothing places (no placement, no command mapping, not left unplaced, not in a group of troopers who no
     * longer serve), and billets in two units at once ({@see conflicts()}). The sidebar's count beside Org structure.
     */
    public function attentionCount(): int;
}
