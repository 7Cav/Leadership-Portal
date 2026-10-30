<?php

namespace App\Commands\Counseling;

/**
 * The trooper's own appeal of an LOR, or comment or dispute on a DCS, filed within the 72 hours after the record was
 * filed; `$statement` is required. Handled by {@see AppealCounselingRecordHandler}.
 */
final readonly class AppealCounselingRecord
{
    public function __construct(
        public int $recordId,
        public ?string $statement,
    ) {}
}
