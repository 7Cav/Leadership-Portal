<?php

namespace App\Commands\ReportEdits;

/**
 * Approve or deny a pending request to edit a locked report, with an optional note for the person who asked.
 * Handled by {@see DecideReportEditHandler}.
 */
final readonly class DecideReportEdit
{
    public function __construct(
        public int $requestId,
        public bool $approve,
        public ?string $note,
    ) {}
}
