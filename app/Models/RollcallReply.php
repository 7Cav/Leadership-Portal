<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A trooper's latest reply on their section's roll call thread for one week: the forum post, when it was made, and
 * the answer read from it, null when none was recognised. Kept so the roll call page can show what the reply said
 * beside a signature a leader set by hand, and list the replies it could not read. No post text is stored. One row
 * per trooper per practice; written only by `Repositories\RollcallReplyRepository` for the reply reader.
 */
#[Fillable(['section_practice_id', 'trooper_id', 'forum_post_id', 'post_url', 'answer', 'posted_at'])]
class RollcallReply extends Model
{
    protected function casts(): array
    {
        return [
            'forum_post_id' => 'integer',
            'answer' => RollcallSignature::class,
            'posted_at' => 'immutable_datetime',
        ];
    }

    public function sectionPractice(): BelongsTo
    {
        return $this->belongsTo(SectionPractice::class);
    }

    public function trooper(): BelongsTo
    {
        return $this->belongsTo(Trooper::class);
    }
}
