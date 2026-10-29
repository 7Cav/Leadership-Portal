<?php

namespace App\Models;

use Database\Factories\PageVisitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One page a signed-in person opened, and when (#382): the Admin-only activity log that lets a bug report be
 * retraced. Only the address (sensitive query values already stripped) and the time, never a form or a request body.
 * `acting_as` / `viewing_as` hold what the shell named for an act as or a view as in effect on the visit.
 *
 * Not an audit entry: a visit changes nothing, so it never goes through the audit envelope (AD-16). Kept for
 * {@see self::RETENTION_DAYS} days, then pruned.
 */
#[Fillable(['user_id', 'url', 'acting_as', 'viewing_as', 'visited_at'])]
class PageVisit extends Model
{
    /** @use HasFactory<PageVisitFactory> */
    use HasFactory;

    /** How long a visit is kept (#382). */
    public const RETENTION_DAYS = 30;

    /** The longest address stored, matching the column; anything longer is cut. */
    public const MAX_URL_LENGTH = 1024;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'visited_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
