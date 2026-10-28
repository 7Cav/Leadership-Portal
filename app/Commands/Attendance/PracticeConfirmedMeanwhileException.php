<?php

namespace App\Commands\Attendance;

use App\Models\SectionPractice;
use RuntimeException;

/**
 * Raised inside the audited transaction when, under the practice's row lock, the practice turns out
 * to hold a confirmation the confirming leader never saw: another leader confirmed it in the
 * meantime. Thrown rather than returned so it rolls the transaction back, which keeps the second
 * leader's stamp out of the row and out of the audit log (AD-16).
 * {@see ConfirmSectionPracticeHandler} catches it and answers the existing confirmation, and
 * {@see MarkAttendanceHandler::preMark()} catches it when a capture pre-mark finds the practice
 * confirmed under its lock, so a pre-mark that wrote nothing leaves no empty entry; it never
 * reaches a caller.
 */
final class PracticeConfirmedMeanwhileException extends RuntimeException
{
    public function __construct(public readonly SectionPractice $practice)
    {
        parent::__construct('Someone else confirmed this practice in the meantime. Reload the page to see it.');
    }
}
