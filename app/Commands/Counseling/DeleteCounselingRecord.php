<?php

namespace App\Commands\Counseling;

/**
 * Delete a counseling record and its document (#381); `$auditReason` says why, and is required. Handled by
 * {@see DeleteCounselingRecordHandler}.
 */
final readonly class DeleteCounselingRecord
{
    public function __construct(
        public int $recordId,
        public ?string $auditReason,
    ) {}
}
