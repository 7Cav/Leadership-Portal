<?php

namespace App\Commands\StaffReports;

use RuntimeException;

/** Only the staff member, signed in as themselves, sends their own staff report (policy, 2026-10-29). */
class StaffReportForbiddenException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Only the staff member can send their own staff report.');
    }
}
