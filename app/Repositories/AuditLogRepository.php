<?php

namespace App\Repositories;

use App\Models\AuditLogEntry;
use App\Models\Unit;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Sole DB access for the audit trail (AD-2, AD-22).
 */
interface AuditLogRepository
{
    /**
     * Run `$mutation` and record its result to `audit_log`, both in one transaction (AD-16) —
     * a mutation that throws never leaves an orphan log, and a crash before the log write rolls
     * the mutation back with it. `$mutation` must return the audited Eloquent Model: its class
     * name and key become `target_type`/`target_id`, and its attributes are hashed into
     * `content_digest`, so every Command that routes through this gets a correct target and
     * digest for free rather than having to state them separately and risk the two drifting
     * apart.
     *
     * `$onBehalfOf` is the user an Admin was acting as (#379), recorded beside the Admin as actor; `$onBehalfOfUnit` the
     * unit whose billet they held; both null otherwise.
     *
     * @throws RuntimeException when `$mutation` doesn't return an Eloquent Model — a clear
     *                          contract violation, not a value this table can log meaningfully.
     */
    public function recordWithMutation(int $actorId, string $action, ?string $reason, Closure $mutation, ?int $onBehalfOf = null, ?int $onBehalfOfUnit = null): mixed;

    /**
     * The batch form of {@see recordWithMutation()}: one transaction, one `audit_log` row, for an
     * action that writes many rows at once. `$mutation` returns an {@see AuditedBatch} — its
     * `target` becomes `target_type`/`target_id`, and `content_digest` hashes the target's stored
     * attributes together with every member's (each re-read after the write, members ordered by
     * class and key, so the digest depends only on what was persisted). A mutation that throws
     * rolls back every row it wrote and leaves no log.
     *
     * A deadlock against a concurrent batch is retried a few times as a whole transaction, so
     * `$mutation` must be safe to run again from the start (idempotent upserts are). The retry
     * only happens when this is the outermost transaction; inside a caller's own transaction a
     * deadlock propagates to that caller.
     *
     * @throws RuntimeException when `$mutation` doesn't return an {@see AuditedBatch}.
     */
    public function recordBatchWithMutation(int $actorId, string $action, ?string $reason, Closure $mutation, ?int $onBehalfOf = null, ?int $onBehalfOfUnit = null): AuditedBatch;

    /**
     * The 1.14 review screen's filtered, most-recent-first, paginated read. Every filter is
     * optional and they combine with AND; the date range is inclusive of both ends since Admin
     * reasons about whole days, not timestamps — `dateTo` is compared against the *start* of the
     * following day rather than wrapped in `whereDate()`, so the comparison stays sargable on the
     * `created_at` index the migration added for exactly this read (AD-22, a table that's never
     * pruned). `actor` is eager-loaded so the controller never lazy-loads it (AD-2's
     * `preventLazyLoading()`). The `id` tiebreaker after `created_at` keeps pagination stable
     * across requests — the column is second-precision, so several entries written within the
     * same second can otherwise land on the same timestamp with no deterministic order.
     *
     * `withinUnit` keeps the entries about `$withinUnit` or anything in its subtree (the materialized-path prefix, AD-18):
     * the unit itself, a section practice or weekly report of one of its units, a template of one of its companies, a staff
     * grant on one of its units, a fireteam row of one of its sections, a fireteam setting, a platoon training topic or a guidon of
     * one of its units, a game-player dismissal of a player one of its sections' practices captured, and a trooper (with
     * their game identities and courses, and the portal user with their `cav_id`) whose section is in it now. The cache
     * holds only a trooper's current section, so a trooper who has since moved counts under their new unit. A target that has since been removed (a fireteam row
     * taken out, a topic cleared, a dismissal undone) no longer places its entries under any unit.
     *
     * @return LengthAwarePaginator<int, AuditLogEntry>
     */
    public function filtered(
        ?int $actorId = null,
        ?string $action = null,
        ?string $targetType = null,
        ?string $targetId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?Unit $withinUnit = null,
        int $perPage = 25,
    ): LengthAwarePaginator;

    /**
     * What each entry is about, as it is stored now: the model behind each entry's `target_type`/`target_id`, keyed
     * `"{target_type}#{target_id}"`, with what a link to it needs loaded. One query per target type among the entries,
     * never one per entry. A target that no longer exists (a deleted unit, a removed identity) and a type with nowhere to
     * link to (a course) are left out. A `GamePlayerDismissal` comes with a `latestCapture` relation set: the newest
     * `PracticeCapturePlayer` that stored the player, with `practice.section` loaded, or null. A `User` comes with a
     * `trooper` relation set: the trooper with the same `cav_id`, or null.
     *
     * @param  iterable<AuditLogEntry>  $entries
     * @return array<string, Model>
     */
    public function targetsOf(iterable $entries): array;

    /**
     * Every user who has at least one `audit_log` entry, for the review screen's actor filter —
     * scoped to actors who actually appear, not every portal-known identity (Story 1.12's
     * `nonAdminUsers()` is the wrong shape here: an Admin can act too, and a user who has never
     * acted would be a filter option that always returns nothing).
     *
     * @return Collection<int, User>
     */
    public function actors(): Collection;

    /**
     * Every distinct `action` value present in the log, for the review screen's action filter.
     *
     * @return Collection<int, string>
     */
    public function actions(): Collection;
}
