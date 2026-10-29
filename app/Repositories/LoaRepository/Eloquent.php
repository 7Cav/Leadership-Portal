<?php

namespace App\Repositories\LoaRepository;

use App\Models\Loa;
use App\Models\LoaParseStatus;
use App\Models\LoaType;
use App\Repositories\LoaRepository;
use App\Repositories\LoaReturn;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class Eloquent implements LoaRepository
{
    public function findByForumThreadId(int $forumThreadId): ?Loa
    {
        return Loa::query()->where('forum_thread_id', $forumThreadId)->first();
    }

    public function forTrooper(int $trooperId): Collection
    {
        return Loa::query()
            ->where('trooper_id', $trooperId)
            ->orderByRaw('starts_at is null')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();
    }

    public function touchingWeeks(array $trooperIds, CarbonImmutable $firstWeekStart, int $weeks): Collection
    {
        if ($trooperIds === [] || $weeks < 1) {
            return new Collection;
        }

        $first = WeekOf::requireStart($firstWeekStart);
        $firstDay = $first->toDateString();
        $nextWeek = WeekOf::next($first, $weeks);
        $nextDay = $nextWeek->toDateString();
        // An undated row shields the Cav week it was posted in and the one after: it can touch the range when
        // it was posted from the start of the week before the range up to the range's end. The resolver then
        // places it week by week.
        $postedFrom = WeekOf::previous($first);

        return Loa::query()
            ->whereIn('trooper_id', $trooperIds)
            ->whereNull('withdrawn_at')
            ->where(function ($query) use ($firstDay, $nextDay, $postedFrom, $nextWeek) {
                $query->where(function ($dated) use ($firstDay, $nextDay) {
                    $dated->where('parse_status', LoaParseStatus::Ok->value)
                        ->whereNotNull('starts_at')
                        // `starts_at` and `ends_at` are DATE columns: plain comparisons, so an index on them stays usable.
                        // Starting before the next week's first day is the same as on or before this Friday,
                        // and holds whether the driver keeps a bare date or a midnight timestamp.
                        ->where('starts_at', '<', $nextDay)
                        ->where(fn ($end) => $end->whereNull('ends_at')->orWhere('ends_at', '>=', $firstDay));
                })->orWhere(function ($undated) use ($postedFrom, $nextWeek, $firstDay, $nextDay) {
                    $undated->where(fn ($unread) => $unread->where('parse_status', LoaParseStatus::NeedsReview->value)->orWhereNull('starts_at'))
                        ->where(fn ($posted) => $posted
                            // The leave dates its post still gives, when it gives usable ones.
                            ->where(fn ($review) => $review->whereNotNull('review_starts_at')->whereNotNull('review_ends_at')->where('review_starts_at', '<', $nextDay)->where('review_ends_at', '>=', $firstDay))
                            ->orWhere(fn ($forum) => $forum->whereNotNull('posted_at')->where('posted_at', '>=', $postedFrom)->where('posted_at', '<', $nextWeek))
                            // A row stored before `posted_at` existed, until the sync reads its thread again.
                            ->orWhere(fn ($cached) => $cached->whereNull('posted_at')->where('created_at', '>=', $postedFrom)->where('created_at', '<', $nextWeek)));
                });
            })
            ->orderBy('id')
            ->get();
    }

    public function settledThreadIds(array $forumThreadIds, CarbonImmutable $endedBefore): array
    {
        if ($forumThreadIds === []) {
            return [];
        }

        return Loa::query()
            ->whereIn('forum_thread_id', $forumThreadIds)
            ->where('parse_status', LoaParseStatus::Ok->value)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', $endedBefore->toDateString())
            ->pluck('forum_thread_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function sync(array $writes, array $returns): array
    {
        return DB::transaction(function () use ($writes, $returns) {
            $created = 0;
            $updated = 0;

            // One read for every row this run touches, not one per thread; only a row that changed is written.
            $existing = [];

            foreach (array_chunk(array_map(fn ($write) => $write->forumThreadId, $writes), 500) as $threadIds) {
                foreach (Loa::query()->whereIn('forum_thread_id', $threadIds)->get() as $row) {
                    $existing[$row->forum_thread_id] = $row;
                }
            }

            foreach ($writes as $write) {
                $loa = $existing[$write->forumThreadId] ?? null;
                $isNew = $loa === null;
                $loa ??= new Loa;

                $attributes = [
                    'trooper_id' => $write->trooperId,
                    'forum_thread_id' => $write->forumThreadId,
                    'forum_node_id' => $write->forumNodeId,
                    'type' => $write->type,
                    'starts_at' => $write->startsAt,
                    'ends_at' => $write->endsAt,
                    'expected_return_at' => $write->expectedReturnAt,
                    'parse_status' => $write->parseStatus,
                    'review_reason' => $write->reviewReason,
                    'review_starts_at' => $write->reviewStartsAt,
                    'review_ends_at' => $write->reviewEndsAt,
                    'source_thread' => $write->sourceThread,
                    'posted_at' => $write->postedAt,
                    'raw_username' => $write->rawUsername,
                    'raw_start_date' => $write->rawStartDate,
                    'raw_end_date' => $write->rawEndDate,
                    'raw_excerpt' => $write->rawExcerpt,
                    'withdrawn_at' => null,
                ];

                // An ELOA already ended by a return keeps the end the return gave it: the thread itself
                // never says so, and would otherwise reopen the leave on every re-read. That holds for a
                // needs_review read too. The end comes from the return thread, not this one, and a return
                // is applied only once, so clearing it here would leave the ELOA open for good once the
                // thread reads cleanly again.
                if (! $isNew && $loa->return_thread_id !== null) {
                    unset($attributes['ends_at']);
                }

                $loa->fill($attributes)->save();

                if ($isNew) {
                    $created++;
                } elseif ($loa->wasChanged()) {
                    $updated++;
                }
            }

            [$closed, $unmatched] = $this->applyReturns($returns);

            return ['created' => $created, 'updated' => $updated, 'closed' => $closed, 'unmatchedReturns' => $unmatched];
        });
    }

    public function withdrawUnseen(array $forumNodeIds, array $seenThreadIds, CarbonImmutable $postedFrom, CarbonImmutable $at): array
    {
        return DB::transaction(function () use ($forumNodeIds, $seenThreadIds, $postedFrom, $at) {
            $seen = array_flip($seenThreadIds);
            $restored = 0;

            foreach (array_chunk($seenThreadIds, 500) as $threadIds) {
                $restored += Loa::query()->whereIn('forum_thread_id', $threadIds)->whereNotNull('withdrawn_at')->update(['withdrawn_at' => null]);
            }

            if ($forumNodeIds === []) {
                return ['withdrawn' => 0, 'restored' => $restored];
            }

            $unseen = Loa::query()
                ->whereIn('forum_node_id', $forumNodeIds)
                ->whereNull('withdrawn_at')
                ->whereNotNull('posted_at')
                ->where('posted_at', '>=', $postedFrom)
                ->pluck('forum_thread_id', 'id')
                ->reject(fn ($threadId) => isset($seen[(int) $threadId]))
                ->keys()
                ->all();

            $withdrawn = 0;

            foreach (array_chunk($unseen, 500) as $ids) {
                $withdrawn += Loa::query()->whereIn('id', $ids)->update(['withdrawn_at' => $at]);
            }

            return ['withdrawn' => $withdrawn, 'restored' => $restored];
        });
    }

    /**
     * @param  list<LoaReturn>  $returns
     * @return array{0: int, 1: int} ended, unmatched
     */
    private function applyReturns(array $returns): array
    {
        $closed = 0;
        $unmatched = 0;

        foreach ($returns as $return) {
            if (Loa::query()->where('return_thread_id', $return->returnThreadId)->exists()) {
                continue;
            }

            $open = Loa::query()
                ->where('trooper_id', $return->trooperId)
                ->where('type', LoaType::Eloa->value)
                ->where('parse_status', LoaParseStatus::Ok->value)
                ->whereNull('return_thread_id')
                ->whereNull('ends_at')
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $return->returnedOn->toDateString()))
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->first();

            if ($open === null) {
                $unmatched++;

                continue;
            }

            $open->forceFill(['ends_at' => $return->returnedOn, 'return_thread_id' => $return->returnThreadId])->save();
            $closed++;
        }

        return [$closed, $unmatched];
    }
}
