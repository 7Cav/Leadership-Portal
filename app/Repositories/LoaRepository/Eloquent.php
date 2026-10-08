<?php

namespace App\Repositories\LoaRepository;

use App\Models\Loa;
use App\Models\LoaParseStatus;
use App\Models\LoaType;
use App\Repositories\LoaRepository;
use App\Repositories\LoaReturn;
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

    public function touchingWeek(array $trooperIds, CarbonImmutable $weekStart): Collection
    {
        if ($trooperIds === []) {
            return new Collection;
        }

        $firstDay = $weekStart->toDateString();
        $lastDay = $weekStart->addDays(6)->toDateString();
        $nextWeek = $weekStart->addDays(7);

        return Loa::query()
            ->whereIn('trooper_id', $trooperIds)
            ->where(function ($query) use ($firstDay, $lastDay, $nextWeek) {
                $query->where(function ($dated) use ($firstDay, $lastDay) {
                    $dated->where('parse_status', LoaParseStatus::Ok->value)
                        ->whereNotNull('starts_at')
                        ->whereDate('starts_at', '<=', $lastDay)
                        ->where(fn ($end) => $end->whereNull('ends_at')->orWhereDate('ends_at', '>=', $firstDay));
                })->orWhere(function ($undated) use ($nextWeek) {
                    $undated->where(fn ($unread) => $unread->where('parse_status', LoaParseStatus::NeedsReview->value)->orWhereNull('starts_at'))
                        ->where('created_at', '<', $nextWeek);
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

            foreach ($writes as $write) {
                $loa = Loa::query()->where('forum_thread_id', $write->forumThreadId)->first();
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
                    'source_thread' => $write->sourceThread,
                    'raw_username' => $write->rawUsername,
                    'raw_start_date' => $write->rawStartDate,
                    'raw_end_date' => $write->rawEndDate,
                    'raw_excerpt' => $write->rawExcerpt,
                ];

                // An ELOA already ended by a return keeps the end the return gave it: the thread itself
                // never says so, and would otherwise reopen the leave on every re-read.
                if (! $isNew && $loa->return_thread_id !== null && $write->parseStatus === LoaParseStatus::Ok) {
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
