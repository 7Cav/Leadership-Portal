<?php

namespace App\Sync;

use App\Integration\Forum\Data\ForumThreadSummary;
use App\Integration\Forum\ForumApi;
use App\Integration\Forum\ForumRequestException;
use App\Models\LoaParseStatus;
use App\Models\LoaType;
use App\Models\SyncRun;
use App\Repositories\LoaRepository;
use App\Repositories\LoaReturn;
use App\Repositories\LoaWrite;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperRepository;
use App\Sync\Loa\LoaPostParser;
use App\Sync\Loa\LoaThreadKind;
use App\Sync\Loa\ParsedLoaThread;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The sole writer of the `loas` cache (AD-4, AD-22). It reads leave threads through {@see ForumApi}, reads
 * each with {@see LoaPostParser}, and applies the whole run through {@see LoaRepository::sync()} as one
 * transaction: nothing is written until every thread has been fetched, so a failure part-way leaves the
 * last good cache exactly as it was (no partial truncation, and no truncation at all: rows are upserted
 * by `forum_thread_id`).
 *
 * Which threads it reads: the forum lists a section newest first by creation date, so a run walks each
 * section only until it reaches a thread older than the last good run (less three hours of overlap), which
 * keeps an hourly run to a page or two. The first run, and a run with `$full`, go back a year instead; a full
 * run skips threads whose leave ended more than two weeks ago, so it stays cheap, and exists to pick up a post
 * that was edited after the incremental runs had passed it. One is scheduled daily.
 * Threads that are not visible, and the pinned notices the forum lists separately, are not read.
 *
 * What it stores: a normal LOA has its dates in the post. An ELOA report has none: it starts the day its
 * PAF was filed, found by following the PAF link and reading that thread's creation date, falling back to
 * the day the report was posted when the link is missing or the PAF cannot be read. An ELOA return does
 * not get a row; it ends the trooper's open ELOA, on the day its own PAF was filed. A thread that cannot
 * be read cleanly, or whose username matches no one (or more than one), is stored as `needs_review` with
 * no dates, never guessed. The trooper is matched on the whole username, ignoring case, never a prefix.
 *
 * The run is recorded in `sync_runs` (AD-16's carve-out for system jobs). A thread that failed to fetch
 * makes it `partial` (and is retried next run, since the cutoff only moves after a clean run). Rows left
 * for a person are counted in `needs_review`, which does not make a run partial. `inactivated` is the roster
 * sync's count of troopers marked departed and has no meaning here, so it stays 0.
 */
class SyncLoasHandler
{
    /** How far back the first run, and a full run, look. */
    private const HISTORY_DAYS = 365;

    /**
     * Added back onto the last good run's start, so a thread posted while it ran (or a little clock skew) is
     * not missed. Kept short on purpose: every thread inside the window is fetched again, so a long overlap
     * is a forum request per recent thread every run. The daily full run is what catches a later edit.
     */
    private const OVERLAP_HOURS = 3;

    /** A full run does not re-read a thread whose leave ended longer ago than this. */
    private const SETTLED_AFTER_DAYS = 14;

    /** A runaway guard on one section's listing. */
    private const MAX_PAGES = 40;

    public function __construct(
        private readonly ForumApi $forum,
        private readonly LoaRepository $loas,
        private readonly TrooperRepository $troopers,
        private readonly SyncRunRepository $syncRuns,
        private readonly LoaPostParser $parser,
    ) {}

    /**
     * @throws Throwable the failure that stopped the run, after it has been recorded
     */
    public function handle(int|string $triggeredBy = 'system', bool $full = false): void
    {
        $run = $this->syncRuns->open(SyncRun::SOURCE_LOA, $triggeredBy);

        try {
            $now = CarbonImmutable::now();
            $horizon = $now->subDays(self::HISTORY_DAYS)->startOfDay();
            $cutoff = $full ? $horizon : $this->cutoff($horizon);
            $problems = [];

            $wanted = $this->wanted($cutoff, $problems);

            if ($full) {
                $wanted = array_diff_key($wanted, array_flip($this->loas->settledThreadIds(array_keys($wanted), $now->subDays(self::SETTLED_AFTER_DAYS)->startOfDay())));
            }

            $items = $this->read($wanted, $now, $problems);

            $troopers = $this->troopers->idsByUsername(array_values(array_filter(array_map(fn (array $item) => $item['parsed']->username, $items))));

            [$writes, $returns, $needsReview] = $this->build($items, $troopers);

            $counts = $this->loas->sync($writes, $returns);

            $this->syncRuns->complete(
                $run,
                inserted: $counts['created'],
                updated: $counts['updated'],
                inactivated: 0,
                errored: count($problems),
                error: $problems === [] ? null : mb_substr(implode('; ', $problems), 0, 2000),
                needsReview: $needsReview + $counts['unmatchedReturns'],
            );
        } catch (Throwable $e) {
            $this->syncRuns->fail($run, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Threads newer than this are read. The last good run's start less the overlap, but never further back
     * than the history horizon.
     */
    private function cutoff(CarbonImmutable $horizon): CarbonImmutable
    {
        $lastGood = $this->syncRuns->latestSuccessful(SyncRun::SOURCE_LOA);

        if ($lastGood === null) {
            return $horizon;
        }

        return CarbonImmutable::instance($lastGood->started_at)->subHours(self::OVERLAP_HOURS)->max($horizon);
    }

    /**
     * The threads to read: each configured section, newest first, until a thread older than the cutoff.
     * A failure listing a section is not caught: the run fails and writes nothing.
     *
     * @param  list<string>  $problems
     * @return array<int, array{summary: ForumThreadSummary, type: LoaType}> keyed by thread id
     */
    private function wanted(CarbonImmutable $cutoff, array &$problems): array
    {
        $wanted = [];

        foreach ($this->forum->loaNodes() as $nodeId => $typeValue) {
            $type = LoaType::from($typeValue);

            for ($page = 1; ; $page++) {
                $this->pause();
                $listing = $this->forum->listLoaThreads($nodeId, $page);
                $reachedOlder = false;

                foreach ($listing->threads as $thread) {
                    if ($thread->postedAt->lessThan($cutoff)) {
                        $reachedOlder = true;

                        break;
                    }

                    if ($thread->isVisible()) {
                        $wanted[$thread->threadId] = ['summary' => $thread, 'type' => $type];
                    }
                }

                if ($reachedOlder || ! $listing->hasMore()) {
                    break;
                }

                if ($page >= self::MAX_PAGES) {
                    $problems[] = "Section {$nodeId}: stopped at the {$page}-page limit before reaching the cutoff";

                    break;
                }
            }
        }

        return $wanted;
    }

    /**
     * Fetch and read each wanted thread. One that fails to fetch is noted and left for the next run.
     *
     * @param  array<int, array{summary: ForumThreadSummary, type: LoaType}>  $wanted
     * @param  list<string>  $problems
     * @return list<array{summary: ForumThreadSummary, type: LoaType, parsed: ParsedLoaThread, eventOn: ?CarbonImmutable, eventNote: ?string}>
     */
    private function read(array $wanted, CarbonImmutable $now, array &$problems): array
    {
        $items = [];
        $pafDates = [];

        foreach ($wanted as $threadId => $want) {
            $this->pause();

            try {
                $thread = $this->forum->fetchLoaThread($threadId);
            } catch (ForumRequestException $e) {
                $problems[] = "Thread {$threadId}: {$e->getMessage()}";

                continue;
            }

            if (! $thread->summary->isVisible()) {
                continue;
            }

            $parsed = $this->parser->parse($thread->summary->title, $thread->firstPostBody, $want['type'], $now);
            $eventOn = null;
            $eventNote = null;

            if (in_array($parsed->kind, [LoaThreadKind::EloaReport, LoaThreadKind::EloaReturn], true) && $parsed->reasons === []) {
                $event = $this->eventDate($parsed, $thread->summary, $pafDates, $problems);

                if ($event === null) {
                    continue;
                }

                [$eventOn, $eventNote] = $event;
            }

            $items[] = ['summary' => $thread->summary, 'type' => $want['type'], 'parsed' => $parsed, 'eventOn' => $eventOn, 'eventNote' => $eventNote];
        }

        return $items;
    }

    /**
     * The day an ELOA report or return takes effect: the day its PAF was filed, from the creation date of
     * the PAF thread it links to. When there is no link, no PAF section is configured, or the forum says no
     * (forbidden, not found, not a PAF thread), the day the post itself was made, with a note saying so. A
     * temporary failure returns null, so the thread is skipped and retried next run rather than dated by
     * the fallback on a bad day.
     *
     * @param  array<int, CarbonImmutable|false>  $pafDates  PAF dates already looked up this run
     * @param  list<string>  $problems
     * @return array{0: CarbonImmutable, 1: string}|null the date and a note on where it came from
     */
    private function eventDate(ParsedLoaThread $parsed, ForumThreadSummary $summary, array &$pafDates, array &$problems): ?array
    {
        $posted = $summary->postedAt->startOfDay();
        $fallback = fn (string $why) => [$posted, "posted {$posted->toDateString()} ({$why})"];

        if ($parsed->pafThreadId === null) {
            return $fallback('no PAF link');
        }

        if ($this->forum->pafNode() === null) {
            return $fallback('PAF section not configured');
        }

        $pafId = $parsed->pafThreadId;

        if (! array_key_exists($pafId, $pafDates)) {
            $this->pause();

            try {
                $pafDates[$pafId] = $this->forum->fetchPafThreadPostedAt($pafId);
            } catch (ForumRequestException $e) {
                if (! $e->isPermanent()) {
                    $problems[] = "Thread {$summary->threadId}: PAF {$pafId}: {$e->getMessage()}";

                    return null;
                }

                $pafDates[$pafId] = false;
            }
        }

        $filed = $pafDates[$pafId];

        return $filed === false ? $fallback("PAF {$pafId} not readable") : [$filed->startOfDay(), "PAF {$pafId} filed {$filed->toDateString()}"];
    }

    /**
     * Turn what was read into rows to store and returns to apply. A thread that cannot be tied to exactly
     * one trooper is kept as `needs_review`, and so is one the parser could not read.
     *
     * @param  list<array{summary: ForumThreadSummary, type: LoaType, parsed: ParsedLoaThread, eventOn: ?CarbonImmutable, eventNote: ?string}>  $items
     * @param  array<string, list<int>>  $troopers  lower-cased username => trooper ids
     * @return array{0: list<LoaWrite>, 1: list<LoaReturn>, 2: int} writes, returns, how many need a person
     */
    private function build(array $items, array $troopers): array
    {
        $writes = [];
        $returns = [];
        $needsReview = 0;

        foreach ($items as ['summary' => $summary, 'type' => $type, 'parsed' => $parsed, 'eventOn' => $eventOn, 'eventNote' => $eventNote]) {
            $reasons = $parsed->reasons;
            $matches = $parsed->username === null ? [] : ($troopers[mb_strtolower($parsed->username)] ?? []);

            if ($parsed->username !== null && count($matches) !== 1) {
                $reasons[] = $matches === [] ? "No trooper is named {$parsed->username}." : count($matches)." troopers are named {$parsed->username}.";
            }

            $trooperId = count($matches) === 1 ? $matches[0] : null;

            if ($parsed->kind === LoaThreadKind::EloaReturn) {
                if ($reasons === [] && $trooperId !== null && $eventOn !== null) {
                    $returns[] = new LoaReturn($summary->threadId, $trooperId, $eventOn);
                } else {
                    $needsReview++;
                }

                continue;
            }

            $startsAt = $parsed->kind === LoaThreadKind::EloaReport ? $eventOn : $parsed->startsAt;
            $endsAt = $parsed->kind === LoaThreadKind::EloaReport ? null : $parsed->endsAt;
            $clean = $reasons === [] && $startsAt !== null;

            if (! $clean) {
                $needsReview++;
            }

            $writes[] = new LoaWrite(
                forumThreadId: $summary->threadId,
                forumNodeId: $summary->nodeId,
                trooperId: $trooperId,
                type: $type,
                startsAt: $clean ? $startsAt : null,
                endsAt: $clean ? $endsAt : null,
                parseStatus: $clean ? LoaParseStatus::Ok : LoaParseStatus::NeedsReview,
                reviewReason: $clean ? null : mb_substr(implode(' ', $reasons ?: ['The start date could not be worked out.']), 0, 250),
                sourceThread: $summary->url ?? $this->forum->threadUrl($summary->threadId),
                rawUsername: $parsed->username,
                rawStartDate: $parsed->kind === LoaThreadKind::EloaReport ? $eventNote : $parsed->rawStart,
                rawEndDate: $parsed->rawEnd,
                rawExcerpt: $parsed->excerpt === '' ? null : $parsed->excerpt,
                expectedReturnAt: $clean ? $parsed->expectedReturnAt : null,
            );
        }

        return [$writes, $returns, $needsReview];
    }

    /** A pause between requests to the forum, so a first backfill does not arrive as a burst. */
    private function pause(): void
    {
        $milliseconds = (int) config('sync.loa_request_delay_ms', 0);

        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
