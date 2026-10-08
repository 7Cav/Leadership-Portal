<?php

namespace App\Support\Audit;

use App\Repositories\AuditedBatch;
use App\Repositories\AuditLogRepository;
use Closure;

/**
 * The one path every state-changing Command routes through (AD-16): records actor, action,
 * target, reason, content digest, and timestamp in the same transaction as the mutation itself
 * (delegated to {@see AuditLogRepository}, since DB access stays Repository-only, AD-2).
 *
 * Reason-required actions are rejected here, before the mutation ever runs — a rejected action
 * leaves no state change and no log entry.
 */
class AuditEnvelope
{
    public function __construct(private readonly AuditLogRepository $auditLog) {}

    /**
     * @throws ReasonRequiredException when `$reasonRequired` is true and no reason is given.
     */
    public function record(int $actorId, string $action, ?string $reason, bool $reasonRequired, Closure $mutation): mixed
    {
        if ($reasonRequired && blank($reason)) {
            throw ReasonRequiredException::forAction($action);
        }

        return $this->auditLog->recordWithMutation($actorId, $action, $reason, $mutation);
    }

    /**
     * {@see record()} for one action that writes many rows: `$mutation` returns an
     * {@see AuditedBatch} and the whole batch commits — or rolls back — with a single log entry
     * (delegated to {@see AuditLogRepository::recordBatchWithMutation()}).
     *
     * @throws ReasonRequiredException when `$reasonRequired` is true and no reason is given.
     */
    public function recordBatch(int $actorId, string $action, ?string $reason, bool $reasonRequired, Closure $mutation): AuditedBatch
    {
        if ($reasonRequired && blank($reason)) {
            throw ReasonRequiredException::forAction($action);
        }

        return $this->auditLog->recordBatchWithMutation($actorId, $action, $reason, $mutation);
    }
}
