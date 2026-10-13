<?php

namespace App\Models;

use Database\Factories\SyncRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per cache sync run (AD-4, AD-16, AD-22): source-agnostic so the roster sync (2.2/2.3)
 * and the future Epic 4 LOA sync share this table rather than each growing their own. Written
 * only by `Repositories\SyncRunRepository` — every attribute here is computed there, so nothing
 * is mass-assignable.
 *
 * No `updated_at`: `started_at`/`finished_at` already carry the run's timing, and a second,
 * framework-managed timestamp would just be a redundant third clock on the same row.
 */
#[Fillable([])]
class SyncRun extends Model
{
    /** @use HasFactory<SyncRunFactory> */
    use HasFactory;

    /** The `source` the LOA sync records its runs under, and the LOA readers look them up by. */
    public const SOURCE_LOA = 'loa';

    public const SOURCE_ROSTER = 'roster';

    /** An echelon leader's sync of one unit; recorded apart so it never moves the roster-wide stamp. */
    public const SOURCE_ROSTER_UNIT = 'roster-unit';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'status' => SyncRunStatus::class,
        ];
    }
}
