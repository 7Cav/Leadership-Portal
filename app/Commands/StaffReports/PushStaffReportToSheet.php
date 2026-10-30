<?php

namespace App\Commands\StaffReports;

/** Copy one sent staff report to the reports Google Sheet as a row. Queued by the send, never part of it. */
final readonly class PushStaffReportToSheet
{
    public function __construct(public int $reportId) {}
}
