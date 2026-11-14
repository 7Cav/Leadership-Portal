<?php

namespace App\Repositories\RollcallReplyRepository;

use App\Models\RollcallReply;
use App\Models\SectionPractice;
use App\Repositories\RollcallReplyRepository;
use App\Repositories\RollcallReplyWrite;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Eloquent implements RollcallReplyRepository
{
    public function replace(SectionPractice $practice, array $replies): void
    {
        DB::transaction(function () use ($practice, $replies) {
            $kept = [];

            foreach ($replies as $reply) {
                RollcallReply::query()->updateOrCreate(
                    ['section_practice_id' => $practice->id, 'trooper_id' => $reply->trooperId],
                    [
                        'forum_post_id' => $reply->postId,
                        'post_url' => $reply->postUrl === null ? null : mb_substr($reply->postUrl, 0, 500),
                        'answer' => $reply->answer,
                        'posted_at' => $reply->postedAt,
                    ],
                );
                $kept[] = $reply->trooperId;
            }

            RollcallReply::query()
                ->where('section_practice_id', $practice->id)
                ->whereNotIn('trooper_id', $kept === [] ? [0] : $kept)
                ->delete();
        });
    }

    public function forPractice(SectionPractice $practice): Collection
    {
        return RollcallReply::query()
            ->where('section_practice_id', $practice->id)
            ->get()
            ->keyBy('trooper_id')
            ->toBase();
    }
}
