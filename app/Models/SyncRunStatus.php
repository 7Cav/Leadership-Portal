<?php

namespace App\Models;

/**
 * A `sync_runs` row's lifecycle state. `InProgress` is set when the run opens and is the only
 * value a crash-without-cleanup can leave behind; `Success`/`Partial`/`Failure` are terminal,
 * assigned only when the run closes.
 */
enum SyncRunStatus: string
{
    case InProgress = 'in_progress';
    case Success = 'success';
    case Partial = 'partial';
    case Failure = 'failure';
}
