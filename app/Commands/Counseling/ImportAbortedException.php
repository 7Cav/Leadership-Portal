<?php

namespace App\Commands\Counseling;

use RuntimeException;
use Throwable;

/**
 * The counseling import (#565) stopped partway because something other than a row was wrong: the counseling disk
 * would not take a document, the database refused a record for a reason other than its key being taken, or the code
 * itself failed. Skipping every row with the same vague reason would hide that, so the run stops instead.
 *
 * `$report` is the run so far: the rows stored before it stopped keep their record ids, the row it stopped on and every
 * row after it are skipped with a reason that says so. The cause is `getPrevious()`, already reported.
 */
class ImportAbortedException extends RuntimeException
{
    public function __construct(public readonly ImportReport $report, public readonly int $stoppedAt, Throwable $previous)
    {
        parent::__construct("The import stopped at CSV line {$stoppedAt}: the record could not be stored. Records stored before it are kept; the rest were not imported. Check the log, then import the CSV again: what was stored is skipped as already imported.", previous: $previous);
    }
}
