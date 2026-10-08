<?php

namespace App\Models;

/**
 * Whether a leave thread's dates were read cleanly. `NeedsReview` is the fail-safe (AD-4): the sync
 * never guesses a date, so a row it could not read is marked for a person and asserts no coverage.
 */
enum LoaParseStatus: string
{
    case Ok = 'ok';
    case NeedsReview = 'needs_review';
}
