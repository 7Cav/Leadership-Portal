<?php

namespace App\Repositories;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

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
    /**
     * @param  list<Model>  $members
     *
     * @throws InvalidArgumentException when a member is not an Eloquent Model.
     */
    public function __construct(
        public Model $target,
        public array $members,
    ) {
        foreach ($members as $member) {
            if (! $member instanceof Model) {
                throw new InvalidArgumentException('Every AuditedBatch member must be an Eloquent Model, got '.get_debug_type($member).'.');
            }
        }
    }
}
