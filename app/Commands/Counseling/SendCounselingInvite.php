<?php

namespace App\Commands\Counseling;

use App\Commands\Auth\ForumPmGrant;

/**
 * Send the wiki's meeting invite PM on a draft (#565): the three Zulu windows the leader can meet in, sent from their own
 * forum account with `$pm`. Handled by {@see SendCounselingInviteHandler}.
 */
final readonly class SendCounselingInvite
{
    /**
     * @param  list<string>  $windows
     */
    public function __construct(
        public int $recordId,
        public array $windows,
        public ?ForumPmGrant $pm,
    ) {}
}
