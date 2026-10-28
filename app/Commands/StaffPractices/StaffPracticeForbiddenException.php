<?php

namespace App\Commands\StaffPractices;

use RuntimeException;

/** The actor may not enter or remove this trooper's practices (#448): neither they themselves, their leader nor an Admin. */
class StaffPracticeForbiddenException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Only the staff member, their staff leader or an Admin can change the practices they attended.');
    }
}
