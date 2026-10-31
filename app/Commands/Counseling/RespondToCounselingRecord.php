<?php

namespace App\Commands\Counseling;

use App\Commands\Auth\ForumPmGrant;

/**
 * The trooper's one response to a shared record (#565): their part of the form and, on an LOR, whether they appeal.
 * `$pm` sends the appeal from their own forum account; only an appeal needs it. Handled by
 * {@see RespondToCounselingRecordHandler}.
 */
final readonly class RespondToCounselingRecord
{
    public function __construct(
        public int $recordId,
        public CounselingResponse $response,
        public ?ForumPmGrant $pm,
    ) {}
}
