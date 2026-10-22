<?php

namespace App\Commands\Attendance;

use App\Models\SectionPractice;
use RuntimeException;

/**
 * Raised inside the audited transaction when, under the practice's row lock, the practice turns out
 * to hold a confirmation the confirming leader never saw: another leader confirmed it in the
 * meantime. Thrown rather than returned so it rolls the transaction back, which keeps the second
 * leader's stamp out of the row and out of the audit log (AD-16).
 * {@see ConfirmSectionPracticeHandler} catches it and answers the existing confirmation; it never
 * reaches a caller.
 */
final class PracticeConfirmedMeanwhileException extends RuntimeException
{
    public function __construct(public readonly SectionPractice $practice)
    {
        parent::__construct("Section practice {$practice->id} was confirmed by someone else in the meantime.");
    }
}
