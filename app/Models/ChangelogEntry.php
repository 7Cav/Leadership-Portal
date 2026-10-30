<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One changelog entry: one change on a (Zulu) date. `body` is the change as one line of plain text; `details` is optional
 * additional information in Markdown, rendered with raw HTML stripped before it reaches a page. `major` also lists it under "Major changes"; every
 * entry is in the full changelog. Written by a changelog
 * editor. Portal-owned (AD-22), written only by the audited `app/Commands/Changelog` Commands through
 * `ChangelogRepository`.
 */
#[Fillable(['date', 'body', 'details', 'major', 'created_by', 'updated_by'])]
class ChangelogEntry extends Model
{
    /** Matches the `body` column. */
    public const MAX_BODY_LENGTH = 300;

    public const MAX_DETAILS_LENGTH = 2000;

    /** The most entries one Add saves at once. */
    public const MAX_BATCH = 50;

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'major' => 'boolean',
        ];
    }
}
