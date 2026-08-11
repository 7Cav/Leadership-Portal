<?php

namespace App\Repositories;

use App\Models\AuditLogEntry;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sole DB access for the audit trail (AD-2, AD-22).
 */
class AuditLogRepository
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
    public function recordWithMutation(int $actorId, string $action, ?string $reason, Closure $mutation): mixed
    {
        return DB::transaction(function () use ($actorId, $action, $reason, $mutation) {
            $result = $mutation();

            if (! $result instanceof Model) {
                throw new RuntimeException(sprintf(
                    'AuditLogRepository::recordWithMutation() mutation for action "%s" must return an Eloquent Model, got %s.',
                    $action,
                    get_debug_type($result),
                ));
            }

            // Raw attributes, sorted by key: independent of insertion order and of any loaded
            // relation or `$hidden`/`$visible` cast, so two rows over identical content always
            // digest identically regardless of how the model reached this method.
            $attributes = $result->getAttributes();
            ksort($attributes);

            AuditLogEntry::query()->forceCreate([
                'actor_id' => $actorId,
                'action' => $action,
                'target_type' => class_basename($result),
                'target_id' => (string) $result->getKey(),
                'reason' => $reason,
                'content_digest' => hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR)),
                'created_at' => now(),
            ]);

            return $result;
        });
    }

    /**
     * The 1.14 review screen's filtered, most-recent-first, paginated read. Every filter is
     * optional and they combine with AND; the date range is inclusive of both ends since Admin
     * reasons about whole days, not timestamps. `actor` is eager-loaded so the controller never
     * lazy-loads it (AD-2's `preventLazyLoading()`).
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
    ): LengthAwarePaginator {
        return AuditLogEntry::query()
            ->with('actor')
            ->when($actorId, fn ($query, $value) => $query->where('actor_id', $value))
            ->when($action, fn ($query, $value) => $query->where('action', $value))
            ->when($targetType, fn ($query, $value) => $query->where('target_type', $value))
            ->when($targetId, fn ($query, $value) => $query->where('target_id', $value))
            ->when($dateFrom, fn ($query, $value) => $query->whereDate('created_at', '>=', $value))
            ->when($dateTo, fn ($query, $value) => $query->whereDate('created_at', '<=', $value))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Every user who has at least one `audit_log` entry, for the review screen's actor filter —
     * scoped to actors who actually appear, not every portal-known identity (Story 1.12's
     * `nonAdminUsers()` is the wrong shape here: an Admin can act too, and a user who has never
     * acted would be a filter option that always returns nothing).
     *
     * @return Collection<int, User>
     */
    public function actors(): Collection
    {
        return User::query()
            ->whereIn('id', AuditLogEntry::query()->select('actor_id')->distinct())
            ->orderBy('username')
            ->get();
    }

    /**
     * Every distinct `action` value present in the log, for the review screen's action filter.
     *
     * @return Collection<int, string>
     */
    public function actions(): Collection
    {
        return AuditLogEntry::query()->select('action')->distinct()->orderBy('action')->pluck('action');
    }
}
