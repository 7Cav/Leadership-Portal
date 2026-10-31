<?php

namespace App\Commands\Counseling;

use App\Commands\Auth\ForumPmGrant;

/**
 * Withdraw a shared record back to draft, with a reason, and tell the trooper by PM (#565). Handled by
 * {@see WithdrawCounselingRecordHandler}.
 */
final readonly class WithdrawCounselingRecord
{
    public function __construct(
        public int $recordId,
        public string $reason,
        public ?ForumPmGrant $pm,
    ) {}
}
