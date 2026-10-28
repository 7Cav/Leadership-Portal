<?php

namespace App\Commands\Counseling;

use Carbon\CarbonImmutable;

/**
 * Correct a counseling record's date or reason (#381); `$auditReason` says why, and is required. The type and the
 * document never change: a record filed as the wrong type, or with the wrong file, is deleted and filed again.
 * Handled by {@see CorrectCounselingRecordHandler}.
 */
final readonly class CorrectCounselingRecord
{
    public function __construct(
        public int $recordId,
        public CarbonImmutable $counseledOn,
        public string $reason,
        public ?string $auditReason,
    ) {}
}
