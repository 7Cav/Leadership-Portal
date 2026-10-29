<?php

namespace App\Commands\Counseling;

/**
 * Overturn a frozen Letter of Reprimand (S6, 2026-10-29); `$reason` says why, is required, and is shown on the record.
 * Handled by {@see OverturnCounselingRecordHandler}.
 */
final readonly class OverturnCounselingRecord
{
    public function __construct(
        public int $recordId,
        public ?string $reason,
    ) {}
}
