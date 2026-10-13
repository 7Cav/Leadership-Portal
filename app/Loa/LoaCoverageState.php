<?php

namespace App\Loa;

/**
 * Whether a trooper is covered by leave in one Cav week (Story 4.4). Three states, not two, because
 * a leave that could not be read is neither: `NeedsReview` is the fail-safe (AD-4) that suppresses an
 * at-risk or AWOL attribution without asserting the trooper is absent.
 */
enum LoaCoverageState: string
{
    case Covered = 'covered';
    case NeedsReview = 'needs_review';
    case None = 'none';
}
