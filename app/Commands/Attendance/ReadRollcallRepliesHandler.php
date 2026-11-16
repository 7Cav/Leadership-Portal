<?php

namespace App\Commands\Attendance;

use App\Integration\Forum\Data\ForumPost;
use App\Integration\Forum\ForumApi;
use App\Integration\Forum\ForumRequestException;
use App\Models\Attendance;
use App\Models\RollcallSignature;
use App\Models\RollcallSource;
use App\Models\SectionPractice;
use App\Models\SyncRun;
use App\Models\Trooper;
use App\Repositories\AttendanceRepository;
use App\Repositories\RollcallReplyRepository;
use App\Repositories\RollcallReplyWrite;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Rollcall\ReplyAnswer;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reads the signatures from the roll call threads' replies. Each section's roll call for a week is one forum thread:
 * the one its leader linked when marking it posted (#703). This reads that thread's replies through the forum API
 * ({@see ForumApi::fetchThreadPosts()}) and sets each replying trooper's signature, so the leader no longer copies the
 * answers over by hand.
 *
 * Which weeks: every practice marked posted with a thread link, from the running week back {@see WEEKS_BACK} weeks (never
 * before `WeekOf::floor()`), whose section report has not been sent (AD-12: a sent week is frozen). Generated demo
 * practices are not read.
 *
 * Whose replies: a reply's author is matched to a trooper on that section's roll call for the week
 * ({@see TrooperRepository::onRollcall()}) by their forum user id, or, for a trooper with no forum id cached yet, by
 * their whole username, ignoring case (never more than one). A reply from anyone else (a leader of another section, a
 * trooper of another section, a guest) is ignored. The opening post is the roll call itself and is not a reply; a
 * deleted or moderated reply is not read. A trooper's latest reply counts.
 *
 * What it writes: the answer {@see ReplyAnswer} reads from the reply. Each trooper's latest reply is kept in
 * `rollcall_replies` (the post, its date and the answer, never its text), so the roll call page can show it beside a
 * signature a leader set and list the replies whose answer was not recognised. The signature goes through
 * {@see AttendanceRepository::signRollcallFromReply()}: a signature a leader set by hand is never overwritten, one a
 * reply gave follows the trooper's latest reply, and a reply with no answer recognised leaves (or puts back) the
 * trooper unsigned. A trooper whose reply was deleted gets the signature it gave taken back.
 *
 * A thread with more than {@see MAX_PAGES} pages, a link that is not a thread on the forum, and a thread the forum
 * refuses or cannot send are each a problem for that week only: nothing of that week is written, since a part read
 * would take back signatures from the replies it did not reach. The run is recorded in `sync_runs` (source
 * `rollcall-replies`): `inserted` counts the signatures set from a reply, `updated` the ones taken back, `needs_review`
 * the replies with no answer recognised, and `errored` the weeks that could not be read, which makes the run
 * `partial`. A system run is covered by that row (AD-16's carve-out for system jobs); there is no actor to audit.
 *
 * The report lock is checked before a week's thread is read and again just before its writes. Like the capture's
 * system pre-mark ({@see MarkAttendanceHandler::preMark()}), the writes are not one transaction with that check, so a
 * report sent in the moment between the two can still see this week's last signatures land.
 */
class ReadRollcallRepliesHandler
{
    /** How many weeks before the running one are still read, while their report is not sent. */
    public const WEEKS_BACK = 4;

    /** The most pages of one thread a run reads; a roll call thread is a page or two. */
    private const MAX_PAGES = 20;

    public function __construct(
        private readonly ForumApi $forum,
        private readonly SectionPracticeRepository $practices,
        private readonly WeeklyReportRepository $reports,
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly AttendanceRepository $attendance,
        private readonly RollcallReplyRepository $replies,
        private readonly SyncRunRepository $syncRuns,
    ) {}

    /**
     * Whether the replies can be read at all: a forum API key is set. The scheduler only runs the reader then, so an
     * install without one does not record a failed run every hour.
     */
    public static function isConfigured(): bool
    {
        return filled(config('services.sevencav.api_key'));
    }

    /**
     * @throws Throwable the failure that stopped the run, after it has been recorded
     */
    public function handle(int|string $triggeredBy = 'system'): RollcallRepliesRead
    {
        // Runs are serialized by the job's lock, so a run still `in_progress` now was killed before it recorded itself.
        $this->syncRuns->failOrphaned(SyncRun::SOURCE_ROLLCALL_REPLIES);

        $run = $this->syncRuns->open(SyncRun::SOURCE_ROLLCALL_REPLIES, $triggeredBy);

        try {
            $now = CarbonImmutable::now();
            $current = WeekOf::start($now);
            $floor = WeekOf::floor($now);
            $from = $current->subWeeks(self::WEEKS_BACK);
            $from = $from->lessThan($floor) ? $floor : $from;

            $read = new RollcallRepliesRead;
            $problems = [];

            foreach ($this->practices->postedWithThreadFrom($from) as $practice) {
                if ($practice->week_start->greaterThan($current) || $this->reports->isSentFor($practice->section_id, $practice->week_start)) {
                    continue;
                }

                try {
                    $read = $read->plus($this->readWeek($practice));
                } catch (ForumRequestException|UnreadableThreadException $e) {
                    $problems[] = "Section practice {$practice->id} (week of ".strtoupper($practice->week_start->format('dMy'))."): {$e->getMessage()}";
                }
            }

            $this->syncRuns->complete(
                $run,
                inserted: $read->signed,
                updated: $read->cleared,
                inactivated: 0,
                errored: count($problems),
                error: $problems === [] ? null : mb_substr(implode('; ', $problems), 0, 2000),
                needsReview: $read->unrecognised,
            );

            return $read->withWeeksFailed(count($problems));
        } catch (Throwable $e) {
            $this->syncRuns->fail($run, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Read one week's thread and write what it says.
     *
     * @throws ForumRequestException when the forum refuses the thread, fails or sends an unusable payload
     * @throws UnreadableThreadException when the link is not a thread, or the thread is too long to read in one run
     */
    private function readWeek(SectionPractice $practice): RollcallRepliesRead
    {
        $threadId = $this->forum->threadIdFromUrl((string) $practice->rollcall_thread_url)
            ?? throw UnreadableThreadException::notAThread();

        $section = $this->units->find($practice->section_id);

        if ($section === null) {
            return new RollcallRepliesRead;
        }

        $roster = $this->troopers->onRollcall($section, $practice->week_start)->troopers;
        $latest = $this->latestReplies($threadId, $roster->all());

        // The report may have been sent while the thread was read.
        if ($this->reports->isSentFor($practice->section_id, $practice->week_start)) {
            return new RollcallRepliesRead;
        }

        $answers = array_map(fn (ForumPost $post) => ReplyAnswer::read($post->message), $latest);

        $this->replies->replace($practice, array_values(array_map(
            fn (int $trooperId) => new RollcallReplyWrite(
                trooperId: $trooperId,
                postId: $latest[$trooperId]->postId,
                postUrl: $latest[$trooperId]->url ?? $this->postUrl($latest[$trooperId]),
                postedAt: $latest[$trooperId]->postedAt,
                answer: $answers[$trooperId],
            ),
            array_keys($latest),
        )));

        $rows = $this->attendance->forPractice($practice)->keyBy('trooper_id');
        $ids = $roster->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $signed = 0;
        $cleared = 0;

        // In trooper-id order, the lock order of every other attendance writer.
        foreach ($ids as $trooperId) {
            $replied = array_key_exists($trooperId, $latest);

            // No reply: only a signature an earlier reply gave has anything to take back.
            if (! $replied && $rows->get($trooperId)?->rollcall_source !== RollcallSource::Reply) {
                continue;
            }

            $answer = $replied ? ($answers[$trooperId] ?? RollcallSignature::Unsigned) : RollcallSignature::Unsigned;
            $row = $this->attendance->signRollcallFromReply($practice, $trooperId, $answer);

            if ($row instanceof Attendance) {
                $answer === RollcallSignature::Unsigned ? $cleared++ : $signed++;
            }
        }

        return new RollcallRepliesRead(
            weeks: 1,
            signed: $signed,
            cleared: $cleared,
            unrecognised: count(array_filter($answers, fn (?RollcallSignature $answer) => $answer === null)),
        );
    }

    /**
     * Each roll call trooper's latest visible reply on the thread, keyed by trooper id. Every page is read; a thread
     * longer than {@see MAX_PAGES} is refused rather than read in part.
     *
     * @param  list<Trooper>  $roster
     * @return array<int, ForumPost>
     *
     * @throws UnreadableThreadException
     */
    private function latestReplies(int $threadId, array $roster): array
    {
        $byForumId = [];
        $byName = [];

        foreach ($roster as $trooper) {
            if ($trooper->forum_user_id !== null) {
                $byForumId[(int) $trooper->forum_user_id] = (int) $trooper->id;
            } elseif (filled($trooper->username)) {
                // A username only stands in for a trooper whose forum id is not cached yet: one with an id is matched
                // by it alone, so a renamed account is never mistaken for someone else.
                $byName[mb_strtolower(trim((string) $trooper->username))][] = (int) $trooper->id;
            }
        }

        $latest = [];
        $page = 1;

        do {
            $posts = $this->forum->fetchThreadPosts($threadId, $page);

            foreach ($posts->posts as $post) {
                if ($post->isFirstPost || ! $post->isVisible() || $post->userId <= 0) {
                    continue;
                }

                $named = $byName[mb_strtolower(trim($post->username))] ?? [];
                $trooperId = $byForumId[$post->userId] ?? (count($named) === 1 ? $named[0] : null);

                if ($trooperId === null) {
                    continue;
                }

                $seen = $latest[$trooperId] ?? null;

                if ($seen === null || $post->postedAt->greaterThan($seen->postedAt) || ($post->postedAt->equalTo($seen->postedAt) && $post->postId > $seen->postId)) {
                    $latest[$trooperId] = $post;
                }
            }

            $page++;

            if ($posts->hasMore() && $page > self::MAX_PAGES) {
                throw UnreadableThreadException::tooLong(self::MAX_PAGES);
            }
        } while ($posts->hasMore());

        ksort($latest);

        return $latest;
    }

    /** A post's own link, for a forum that did not send one. */
    private function postUrl(ForumPost $post): string
    {
        return rtrim((string) config('services.sevencav.base_url'), '/')."/posts/{$post->postId}/";
    }
}
