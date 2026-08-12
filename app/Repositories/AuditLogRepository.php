<?php

namespace App\Repositories;

use App\Models\AuditLogEntry;
use App\Models\User;
use Closure;
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
     * @throws RuntimeException when `$mutation` doesn't return an Eloquent Model — a clear
     *                          contract violation, not a value this table can log meaningfully.
     */
    public function recordWithMutation(int $actorId, string $action, ?string $reason, Closure $mutation): mixed;

    /**
     * The 1.14 review screen's filtered, most-recent-first, paginated read. Every filter is
     * optional and they combine with AND; the date range is inclusive of both ends since Admin
     * reasons about whole days, not timestamps — `dateTo` is compared against the *start* of the
     * following day rather than wrapped in `whereDate()`, so the comparison stays sargable on the
     * `created_at` index the migration added for exactly this read (AD-22, a table that's never
     * pruned). `actor` is eager-loaded so the controller never lazy-loads it (AD-2's
     * `preventLazyLoading()`). The `id` tiebreaker after `created_at` keeps pagination stable
     * across requests — the column is second-precision, so an audited batch (several rows from
     * one action) can otherwise land on the same timestamp with no deterministic order.
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
        int $perPage = 25,
    ): LengthAwarePaginator;

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
