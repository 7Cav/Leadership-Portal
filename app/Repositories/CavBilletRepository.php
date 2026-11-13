<?php

namespace App\Repositories;

use App\Models\CavBillet;
use App\Models\CavBilletAcknowledgement;
use Illuminate\Support\Collection;

/**
 * Sole DB access for the billets the Cav lists (`cav_billets`): the name each carries now, the name its placement was
 * confirmed under, and whether an Admin chose to leave it unplaced. The org import and the roster sync record what they
 * see ({@see observe()}); an Admin's confirmation or choice is written only by the audited Commands in
 * `App\Commands\Units` (`ConfirmBilletName`, `AssignBillet`, `LeaveBilletUnplaced`), as is an Admin's acknowledgement
 * of a billet in two units at once (`AcknowledgeBilletConflict`, `UndoBilletAcknowledgement`).
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
     * Each also carries its `key` ({@see conflictKey()}), its `placements` (the exact billets, units and sources, as one
     * string) and whether an Admin `acknowledged` it. An acknowledgement holds only while the placements are exactly
     * the ones acknowledged: a billet added, moved or removed brings the warning back.
     *
     * @return list<array{kind: string, key: string, title: string, placements: string, acknowledged: bool, units: list<array{unitId: int, positionId: int, source: string}>}>
     */
    public function conflicts(): array;

    /**
     * The key of the conflict of `$kind` (`title` or `rule`) the billet is in or would be in: `title:<name>` by its name
     * (trimmed, any case), `rule:<position id>` by the billet itself.
     */
    public function conflictKey(string $kind, int $positionId, string $title): string;

    /** Acknowledge the conflict as it sits now, replacing an earlier acknowledgement of other placements. */
    public function acknowledge(string $key, string $title, string $placements): CavBilletAcknowledgement;

    /** Remove the acknowledgement of the conflict; returns the removed row, or null when there was none. */
    public function unacknowledge(string $key): ?CavBilletAcknowledgement;

    /**
     * How many billets wait for an Admin: placed billets the Cav renamed since their placement was confirmed, new
     * billets nothing places (no placement, no command mapping, not left unplaced, not in a group of troopers who no
     * longer serve), and billets in two units at once ({@see conflicts()}) no Admin acknowledged. The sidebar's count
     * beside Org structure.
     */
    public function attentionCount(): int;
}
