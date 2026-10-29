<?php

namespace App\Commands\StaffReports;

use RuntimeException;

/** Only the staff member, signed in as themselves, sends their own staff report. */
class StaffReportForbiddenException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Only the staff member can send their own staff report.');
    }
}
