<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One changelog entry: one change on a (Zulu) date. `body` is the change as one line of plain text; `details` is optional
 * additional information in Markdown, rendered with raw HTML stripped before it reaches a page. `major` also lists it under "Major changes"; every
 * entry is in the full changelog. `technical` marks behind-the-scenes or security work, never major, shown only when
 * the viewer asks for it and an Admin made technical items available (the editors always see them). Written by a changelog
 * editor. Portal-owned (AD-22), written only by the audited `app/Commands/Changelog` Commands through
 * `ChangelogRepository`.
 *
 * A delete all sets `deleted_at` instead of removing the rows ({@see SoftDeletes}), so every query leaves them out. For
 * {@see RESTORE_HOURS} hours an editor can bring them back as they were; after that they count as gone and
 * `portal:purge-changelog` removes them. Deleting one entry removes it at once.
 */
#[Fillable(['date', 'body', 'details', 'major', 'technical', 'created_by', 'updated_by'])]
class ChangelogEntry extends Model
{
    use SoftDeletes;

    /** How long entries removed by a delete all can be restored. */
    public const RESTORE_HOURS = 72;

    /** Matches the `body` column. */
    public const MAX_BODY_LENGTH = 300;

    public const MAX_DETAILS_LENGTH = 2000;

    /** The most entries one Add saves at once. */
    public const MAX_BATCH = 50;

    /**
     * The change text may carry inline emphasis only (`**bold**`, `*italic*`, `_italic_`), to name a feature; the page
     * renders it with `resources/js/lib/inlineEmphasis.js`, everything else stays text. This is the text without the
     * markers, for places that show plain text such as audit labels. The stored text keeps them, so an export
     * round-trips.
     */
    public static function plainText(string $text): string
    {
        $text = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '$1', $text) ?? $text;
        $text = preg_replace('/\*(?=[^\s*])(.+?)(?<=[^\s*])\*/u', '$1', $text) ?? $text;

        return preg_replace('/(?<![\p{L}\p{N}_])_(?=\S)(.+?)(?<=\S)_(?![\p{L}\p{N}_])/u', '$1', $text) ?? $text;
    }

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'major' => 'boolean',
            'technical' => 'boolean',
        ];
    }
}
