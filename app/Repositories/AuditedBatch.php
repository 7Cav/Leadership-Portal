<?php

namespace App\Repositories;

use Illuminate\Database\Eloquent\Model;

/**
 * What a batch mutation hands {@see AuditLogRepository::recordBatchWithMutation()}: the one model
 * the action is *about* (`target`, which becomes the audit row's `target_type`/`target_id`) and
 * every row the batch wrote (`members`, digested together with the target). One audited action
 * that touches many rows — a week's attendance marks against one practice — logs once, with a
 * digest that changes whenever any written row does, rather than once per row or with a digest
 * of the target alone.
 */
final readonly class AuditedBatch
{
    /** @var list<Model> */
    public array $members;

    public function __construct(
        public Model $target,
        Model ...$members,
    ) {
        $this->members = array_values($members);
    }
}
