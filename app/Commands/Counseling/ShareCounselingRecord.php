<?php

namespace App\Commands\Counseling;

use App\Commands\Auth\ForumPmGrant;

/**
 * Share a draft with the trooper (#565): lock the leader's part, snapshot Section 1 and send the wiki's After-Action PM
 * from the leader's own forum account with `$pm`. Handled by {@see ShareCounselingRecordHandler}.
 */
final readonly class ShareCounselingRecord
{
    public function __construct(
        public int $recordId,
        public ?ForumPmGrant $pm,
    ) {}
}
