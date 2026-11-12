<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One page a person bookmarked for the sidebar's Bookmarks section: a route on the allowlist in
 * `App\Bookmarks\BookmarkablePages` and its parameters (`params`, canonical JSON with sorted keys). A personal UI
 * preference of the signed-in user, so it is never audited. A bookmark the person can no longer open is deleted the
 * next time their bookmarks are read.
 */
#[Fillable(['user_id', 'route', 'params', 'label', 'position'])]
class Bookmark extends Model
{
    /** How many bookmarks one person may keep. */
    public const LIMIT = 10;

    /**
     * The parameters as an array.
     *
     * @return array<string, int|string>
     */
    public function parameters(): array
    {
        $decoded = json_decode($this->params, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * `$params` as the canonical text stored in `params`: keys sorted, so the same page is always the same string and
     * the unique index catches a second bookmark of it.
     *
     * @param  array<string, int|string>  $params
     */
    public static function encodeParams(array $params): string
    {
        ksort($params);

        return json_encode((object) $params, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
