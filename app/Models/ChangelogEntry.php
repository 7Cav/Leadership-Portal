<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One changelog entry: a date, a title and a Markdown body, written by a changelog editor. Portal-owned (AD-22), written
 * only by the audited `app/Commands/Changelog` Commands through `ChangelogRepository`. The body is rendered with raw
 * HTML stripped before it reaches a page.
 */
#[Fillable(['date', 'title', 'body', 'created_by', 'updated_by'])]
class ChangelogEntry extends Model
{
    public const MAX_TITLE_LENGTH = 200;

    /** `body` is a TEXT column (64 KB on MySQL); kept well under it. */
    public const MAX_BODY_LENGTH = 20000;

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
        ];
    }
}
