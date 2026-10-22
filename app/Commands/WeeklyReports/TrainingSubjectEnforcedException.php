<?php

namespace App\Commands\WeeklyReports;

use RuntimeException;

/**
 * A section report tried to record a training subject other than the one its platoon leader set
 * and enforced for the week. Thrown before anything is written, so the report controller can turn
 * it into a form error.
 */
class TrainingSubjectEnforcedException extends RuntimeException
{
    public function __construct(int $sectionId)
    {
        parent::__construct("The platoon enforces this week's training topic; section {$sectionId} cannot change it.");
    }
}
