<?php

namespace App\Commands\Attendance;

use RuntimeException;

/**
 * Raised inside {@see MarkAttendanceHandler::withdrawMakeup()}'s audited transaction when, under the row lock, the
 * trooper's own mark turns out not to be an automatic makeup any more (a leader marked it, or a capture made it
 * `present`). Thrown rather than returned so the transaction rolls back without an `attendance.auto_unmarked` entry
 * that changed nothing (AD-16); the handler catches it and answers `MakeupWithdrawal::Kept`. It never reaches a caller.
 */
final class NoAutoMakeupException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The trooper\'s mark is not an automatic makeup, so there is nothing to take back.');
    }
}
