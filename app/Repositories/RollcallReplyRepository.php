<?php

namespace App\Repositories;

use App\Models\RollcallReply;
use App\Models\SectionPractice;
use Illuminate\Support\Collection;

/**
 * Sole DB access for `rollcall_replies` (AD-2): each trooper's latest reply on their section's roll call thread for a
 * week, kept for the roll call page. One writer, the reader of the thread's replies
 * (`Commands\Attendance\ReadRollcallRepliesHandler`), which replaces a practice's replies with what the thread holds
 * now on every read. The signatures themselves live on `attendance`, written through {@see AttendanceRepository}.
 */
interface RollcallReplyRepository
{
    /**
     * Make `$replies` the practice's replies: one row per trooper, updated in place, and every other row of the
     * practice deleted (a reply removed from the thread, or from someone no longer on the roll call). One transaction.
     *
     * @param  list<RollcallReplyWrite>  $replies
     */
    public function replace(SectionPractice $practice, array $replies): void;

    /**
     * The practice's replies keyed by trooper id. Empty when none were read. One query.
     *
     * @return Collection<int, RollcallReply>
     */
    public function forPractice(SectionPractice $practice): Collection;
}
