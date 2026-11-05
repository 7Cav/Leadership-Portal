<?php

namespace App\Commands\WeeklyReports;

use RuntimeException;

/**
 * A section's report sent before its leader said whether the week's practice was held (#634). Sending locks the roll
 * call, so a practice left unconfirmed then could never be confirmed. Thrown before anything is written, and again
 * under the report's lock, so the report controller turns it into a form error. A section with nobody on its roll
 * call has nothing to confirm.
 */
class PracticeNotConfirmedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Confirm on the roll call whether this week\'s practice was held before you send the report.');
    }
}
