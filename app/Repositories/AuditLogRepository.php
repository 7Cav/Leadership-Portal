<?php

namespace App\Repositories;

use App\Models\AuditLogEntry;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
     */
    public function recordWithMutation(int $actorId, string $action, ?string $reason, Closure $mutation): mixed
    {
        return DB::transaction(function () use ($actorId, $action, $reason, $mutation) {
            /** @var Model $result */
            $result = $mutation();

            AuditLogEntry::query()->forceCreate([
                'actor_id' => $actorId,
                'action' => $action,
                'target_type' => class_basename($result),
                'target_id' => (string) $result->getKey(),
                'reason' => $reason,
                'content_digest' => hash('sha256', json_encode($result)),
                'created_at' => now(),
            ]);

            return $result;
        });
    }
}
