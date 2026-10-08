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
 * ended ELOA thread has no end, and a `needs_review` row asserts no dates. `trooper_id` is
 * null while the thread's username matches no trooper, so an unmatched request stays visible.
 *
 * One row is one leave thread. The ELOA section also holds '[ELOA Return]' threads: those are not leave
 * periods, they end the trooper's open ELOA, so the sync (4.2) uses them to set that row's end and
 * gives them no row of their own.
 */
#[Fillable([
    'trooper_id', 'forum_thread_id', 'return_thread_id', 'forum_node_id', 'type', 'starts_at', 'ends_at', 'expected_return_at', 'parse_status',
    'review_reason', 'source_thread', 'raw_username', 'raw_start_date', 'raw_end_date', 'raw_excerpt',
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
            'parse_status' => LoaParseStatus::class,
        ];
    }

    public function trooper(): BelongsTo
    {
        return $this->belongsTo(Trooper::class);
    }
}
