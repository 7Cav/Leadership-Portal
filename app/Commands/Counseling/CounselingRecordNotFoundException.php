<?php

namespace App\Commands\Counseling;

use RuntimeException;

/**
 * No such counseling record or trooper, or one the actor may not see (#381). The controller answers 404 for both, so a
 * record outside someone's view cannot be told from one that does not exist.
 */
class CounselingRecordNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This record no longer exists. Reload the page.');
    }
}
