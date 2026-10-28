<?php

namespace App\Support\Audit;

/**
 * What {@see ActingFor} held when work was queued (#379, #432), carried on the queued command so the job records the
 * same attribution the request would have. `ActingFor` is scoped to one request or job, so a job running later in a
 * worker starts with it empty; without this, an entry written by the job names the person acted as, with no
 * `on_behalf_of`, as if they had done it themselves.
 *
 * Exactly one of `$userId` (acting as a person) and `$unitId` (acting as a billet) is set.
 */
final readonly class ActingSnapshot
{
    public function __construct(
        public int $adminId,
        public ?int $userId = null,
        public ?int $unitId = null,
    ) {}
}
