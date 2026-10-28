<?php

namespace App\Commands\WeeklyReports;

use RuntimeException;

/**
 * Raised inside the audited transaction when clearing a platoon's training topic turns out, under the repository's
 * lock, to clear nothing: a racing request already cleared it. Thrown rather than returned so it rolls the
 * transaction back, which keeps work that did not happen out of the audit log (AD-16), the same way as
 * `GrantUnchangedException` for staff grants. {@see SetPlatoonTrainingTopicHandler} catches it and answers as its own
 * no-op does; it never reaches a caller.
 */
final class TrainingTopicUnchangedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The platoon training topic was already cleared. Reload the page to see it.');
    }
}
