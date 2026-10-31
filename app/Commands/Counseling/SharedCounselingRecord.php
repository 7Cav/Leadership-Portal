<?php

namespace App\Commands\Counseling;

use App\Models\CounselingRecord;

/**
 * A record just shared (#565), and what the leader has to do by hand because a copy could not go: `$followUp` says so
 * when the LOR's copy to S1 and the oversight officer was not sent (nobody to send it to, or the forum refused it after
 * the share had already happened). Null when nothing is left to do.
 */
final readonly class SharedCounselingRecord
{
    public function __construct(
        public CounselingRecord $record,
        public ?string $followUp = null,
    ) {}
}
