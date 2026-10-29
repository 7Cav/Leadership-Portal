<?php

namespace App\Repositories;

use App\Models\AdminBillet;
use App\Models\AdminBilletStatus;
use Illuminate\Support\Collection;

/**
 * Sole DB access for the Regimental HQ and S6 HQ billets that make their holder an Admin (AD-2, policy,
 * 2026-10-28). Read by ScopeResolver's Admin rule and the Staff grants page; the names Cav shows are recorded by the org import and
 * the roster sync ({@see observe()}), and a decision only by the audited `DecideAdminBillet` Command.
 */
interface AdminBilletRepository
{
    /**
     * The rows of `$positionIds` that exist, keyed by `cav_position_id`. One query, none for an empty list.
     *
     * @param  list<int>  $positionIds
     * @return Collection<int, AdminBillet>
     */
    public function forPositions(array $positionIds): Collection;

    /**
     * Every row, pending first, then rejected, then approved, each by the title seen now.
     *
     * @return Collection<int, AdminBillet>
     */
    public function all(): Collection;

    public function find(int $id): ?AdminBillet;

    /** How many rows wait for an Admin's decision. */
    public function pendingCount(): int;

    /**
     * Record the names Cav shows now for these positions, `position id => [title, group title]`, a null group meaning
     * "not known here" (the roster sync sees titles only), which keeps the group last seen. A row whose names differ
     * from what was confirmed goes back to `pending`; a rejected one goes back to `pending` only when a name changed
     * since. A position with no row gets a `pending` one when `$createMissing` (the import's Admin billet groups),
     * and is skipped otherwise. Nothing is ever approved here. Returns how many rows were created or changed. Names
     * are trimmed before they are compared or stored, so a stray space is never a rename.
     *
     * @param  array<int, array{0: string, 1: ?string}>  $seen
     */
    public function observe(array $seen, bool $createMissing): int;

    /**
     * Lock the row for a decision, inside the caller's transaction, and return it as stored; null when it is gone.
     */
    public function lockForDecision(int $id): ?AdminBillet;

    /**
     * Decide the row: `Approved` confirms the names seen now, `Rejected` leaves the confirmed ones as they were. The
     * decider, the Admin acting as them and the billet an Admin held (#379) are recorded with the time.
     */
    public function decide(AdminBillet $billet, AdminBilletStatus $status, int $decidedBy, ?int $decidedByAdmin, ?int $decidedAsUnit): AdminBillet;
}
