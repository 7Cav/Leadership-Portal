<?php

namespace App\Models;

use Database\Factories\LoaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A leave of absence read from a forum thread (AD-5): the forum is the server of record, so this is
 * a cache row (AD-22) and `forum_thread_id` is its authoritative key. Its one writer will be the
 * LOA sync (4.2), through `LoaRepository`; nothing else writes it, which is why every column is
 * mass-assignable here and none of them comes from a request.
 *
 * `starts_at` and `ends_at` are calendar days, inclusive at both ends. Either can be null: an open
 * ended ELOA thread has no end, and a `needs_review` row asserts no dates of its own (an ELOA a return
 * ended keeps the return's `ends_at`; coverage ignores the dates of a `needs_review` row). `trooper_id` is
 * null while the thread's username matches no trooper, so an unmatched request stays visible.
 * `posted_at` is when the thread was posted on the forum (null on a row not re-read since the column
 * was added), which is not `created_at`, the day the portal first stored it. A complete full sync that no longer
 * finds the thread (deleted, hidden or moved out of the leave sections) deletes the row (#690). `withdrawn_at` is
 * what an earlier version set instead: a row that still has it no longer counts for coverage, and the next full
 * sync deletes it if its thread is still gone. `forum_node_id` is the forum section an ELOA was read from; a
 * dated LOA is read from the roster API, which names only the LOA forum (`forum`: `1-7`, `2-7`, `3-7`, `acd`,
 * `regimental`), so its node id is null.
 * `review_starts_at`/`review_ends_at` are the leave dates a `needs_review` row's post still gives, kept apart
 * from `starts_at`/`ends_at` because they assert no leave: they only place the row's needs-review shield.
 *
 * One row is one leave thread. The ELOA section also holds '[ELOA Return]' threads: those are not leave
 * periods, they end the trooper's open ELOA, so the sync (4.2) uses them to set that row's end and
 * gives them no row of their own.
 */
#[Fillable([
    'trooper_id', 'forum_thread_id', 'return_thread_id', 'forum_node_id', 'forum', 'type', 'starts_at', 'ends_at', 'expected_return_at', 'parse_status',
    'review_reason', 'review_starts_at', 'review_ends_at', 'source_thread', 'posted_at', 'withdrawn_at', 'raw_username', 'raw_start_date', 'raw_end_date', 'raw_excerpt',
])]
class Loa extends Model
{
    /** @use HasFactory<LoaFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'forum_thread_id' => 'integer',
            'return_thread_id' => 'integer',
            'forum_node_id' => 'integer',
            'type' => LoaType::class,
            'starts_at' => 'date',
            'ends_at' => 'date',
            'expected_return_at' => 'date',
            'review_starts_at' => 'date',
            'review_ends_at' => 'date',
            'posted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'parse_status' => LoaParseStatus::class,
        ];
    }

    public function trooper(): BelongsTo
    {
        return $this->belongsTo(Trooper::class);
    }
}
