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
 * each with {@see LoaPostParser}, and applies it through {@see LoaRepository::sync()}, a section (or, going
 * back through history, a page) at a time, each as one transaction. Rows are upserted by `forum_thread_id`,
 * never truncated, so a failure part-way leaves what was already saved, and nothing else changes. A run that
 * dies without recording itself (a killed worker) is closed as failed by the next run.
 *
 * Which threads it reads: the forum lists a section newest first by creation date. An hourly run carries on
 * from the cursor the last run saved in `sync_runs.cursor`, per section:
 * - New posts: it walks the section from the top until a thread older than the last time that section's new
 *   posts were read (less three hours of overlap), which keeps an hourly run to a page or two.
 * - The first read of the year (the backfill): a section with no history yet goes back a year, page by page,
 *   older and older, but only so far in one run (40 pages, and ten minutes across sections, well inside the
 *   job's timeout). The cursor keeps the page it got to and the oldest post it reached, so the next run
 *   continues there instead of starting over (stepping back a page if posts deleted since moved the listing
 *   up); threads newer than that point are not read again. Leave already
 *   stored that ended more than two weeks ago is not read again either. Until every section has reached the
 *   year back, the cursor says so, and the freshness note on the pages shows how far back it has got.
 * - Threads to try again: a thread that failed to fetch is kept on the cursor by id and read again next run,
 *   so the rest of the cursor can move on without it.
 * A run with `$full` goes back the whole year from the top, skips threads whose leave ended more than two weeks
 * ago, so it stays cheap, and exists to pick up a post that was edited after the hourly runs had passed it. One
 * is scheduled daily. It leaves the cursor alone. Threads that are not visible, and the pinned notices the forum
 * lists separately, are not read.
 *
 * What it stores: a normal LOA has its dates in the post. An ELOA report has none: it starts the day its
 * PAF was filed, found by following the PAF link and reading that thread's creation date, falling back to
 * the day the report was posted when the link is missing or the PAF cannot be read. An ELOA return does
 * not get a row; it ends the trooper's open ELOA, on the day its own PAF was filed. A thread that cannot
 * be read cleanly (one whose title names a different trooper from its `Username` among them), or whose
 * username matches no one (or more than one), is stored as `needs_review` with no dates, never guessed (the
 * leave dates its post still gives, if usable, are kept apart, only to place its shield: see LoaCoverage). The
 * trooper is matched on the whole username, ignoring case, never a prefix. Raw text longer than its column
 * (a username, or a date line someone wrote a paragraph into) is cut to fit, and the row is sent to review,
 * so one over-long post cannot fail the run for every other post in it. Every row keeps the moment its
 * thread was posted (`posted_at`), the one date a `needs_review` row has.
 *
 * The run is recorded in `sync_runs` (AD-16's carve-out for system jobs). A thread that failed to fetch
 * makes it `partial` (and is kept to retry, see above). A listing cut short by the 40-page guard does too, and
 * leaves that section's new-posts mark where it was. A thread the forum says is gone for good (deleted, moved
 * out of reach, forbidden) is skipped without that: it can never be read, so counting it would keep every
 * later run partial. A backfill that is still going is not a problem in itself: the run read everything it
 * set out to. Rows left for a person are counted in `needs_review`, which does not make a run partial.
 *
 * A thread that goes away: a full run that finished with no problem has seen every visible thread posted in
 * its window, in every section. A stored row from one of those sections, posted in that window, whose thread
 * it did not see (deleted, hidden, or moved out of the leave sections) is marked withdrawn, so it stops
 * excusing the trooper; the row is kept as history, and is put back if a later run sees the thread again.
 * A partial or failed run, and an incremental one, never withdraws anything, since it did not see everything.
 * Withdrawn rows are counted in `inactivated`; rows put back count as `updated`.
 */
class SyncLoasHandler
{
    /** How far back the first run, and a full run, look. */
    private const HISTORY_DAYS = 365;

    /**
     * Added back onto the start of the run that last read a section's new posts, so a thread posted while it
     * ran (or a little clock skew) is not missed. Kept short on purpose: every thread inside the window is fetched again, so a long overlap
     * is a forum request per recent thread every run. The daily full run is what catches a later edit.
     */
    private const OVERLAP_HOURS = 3;

    /** A full run does not re-read a thread whose leave ended longer ago than this. */
    private const SETTLED_AFTER_DAYS = 14;

    /**
     * The length of the `loas` string columns the raw text goes into (`raw_username`, `raw_start_date`,
     * `raw_end_date`, `source_thread`: Laravel's default `string`, `varchar(255)`). A strict MySQL refuses a
     * longer value outright, which would fail the whole run's transaction, so nothing longer is ever sent.
     */
    private const RAW_LENGTH = 255;

    /**
     * A runaway guard on one section's listing, and the most pages of a section's first read of the year one run
     * takes on: the next run carries on from there.
     */
    private const MAX_PAGES = 40;

    /**
     * How long one run spends going back through history, across its sections, before it leaves the rest to the next
     * run. Well under the job's 15 minute timeout, so a run ends on its own and records itself.
     */
    private const BACKFILL_SECONDS = 600;

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
        // Runs are serialized by the job's lock, so any run still `in_progress` now was killed before it could
        // record itself. Closing it keeps the sync's state honest instead of showing it running for ever.
        $this->syncRuns->failOrphaned(SyncRun::SOURCE_LOA);

        $run = $this->syncRuns->open(SyncRun::SOURCE_LOA, $triggeredBy);

        try {
            $now = CarbonImmutable::now();
            $horizon = $now->subDays(self::HISTORY_DAYS)->startOfDay();
            $problems = [];
            $tally = ['created' => 0, 'updated' => 0, 'needsReview' => 0];
            $withdrawn = 0;

            if ($full) {
                $withdrawn = $this->fullRun($now, $horizon, $problems, $tally);
            } else {
                $this->incrementalRun($run, $now, $horizon, $problems, $tally);
            }

            $this->syncRuns->complete(
                $run,
                inserted: $tally['created'],
                updated: $tally['updated'],
                inactivated: $withdrawn,
                errored: count($problems),
                error: $problems === [] ? null : mb_substr(implode('; ', $problems), 0, 2000),
                needsReview: $tally['needsReview'],
            );
        } catch (Throwable $e) {
            $this->syncRuns->fail($run, $e->getMessage());

            throw $e;
        }
    }

    /**
     * A full run: every section back to the horizon, skipping settled leave, then, when nothing went wrong, the
     * withdrawal of rows whose thread is gone. It leaves the cursor alone: that belongs to the hourly runs.
     *
     * @param  list<string>  $problems
     * @param  array{created: int, updated: int, needsReview: int}  $tally
     * @return int how many rows were withdrawn
     */
    private function fullRun(CarbonImmutable $now, CarbonImmutable $horizon, array &$problems, array &$tally): int
    {
        // Every visible thread the listings showed, and the sections they came from, for withdrawUnseen().
        $seen = [];
        $nodes = [];
        $retry = [];

        foreach ($this->forum->loaNodes() as $nodeId => $typeValue) {
            $nodeId = (int) $nodeId;
            $type = LoaType::from($typeValue);
            [$listed, $complete] = $this->listDownTo($nodeId, $horizon);

            if (! $complete) {
                $problems[] = "Section {$nodeId}: stopped at the ".self::MAX_PAGES.'-page limit before reaching the cutoff';
            }

            $nodes[] = $nodeId;
            $seen += array_fill_keys(array_keys($listed), true);
            $wanted = array_diff_key(array_map(fn () => $type, $listed), array_flip($this->settled(array_keys($listed), $now)));

            $this->apply($this->read($wanted, $nodeId, $now, $problems, $seen, $retry), $tally);
        }

        if ($problems !== []) {
            return 0;
        }

        // The listings page by offset, so a thread deleted while the run pages through a section moves the rest up,
        // and one can fall between two pages unseen (the 2026-10-29 audit). Each stored thread the listings missed is
        // asked for by id before anything is withdrawn: one the forum still shows in a leave section stays.
        foreach ($this->loas->unseenThreadIds($nodes, array_keys($seen), $horizon) as $threadId) {
            $this->pause();

            try {
                if ($this->forum->fetchLoaThread($threadId)->summary->isVisible()) {
                    $seen[$threadId] = true;
                }
            } catch (ForumRequestException $e) {
                // Gone (not found, forbidden, or out of the leave sections) is what withdrawing is for; anything else
                // leaves the answer open, so nothing is withdrawn this run.
                if (! $e->isPermanent() && ! $e->outsideLeaveSections) {
                    $problems[] = "Thread {$threadId}: {$e->getMessage()}";
                }
            }
        }

        if ($problems !== []) {
            return 0;
        }

        $result = $this->loas->withdrawUnseen($nodes, array_keys($seen), $horizon, $now);
        $tally['updated'] += $result['restored'];

        return $result['withdrawn'];
    }

    /**
     * An hourly run, carrying on from the cursor the last run saved. First, in each section, the threads posted since
     * the last run and those an earlier run could not read. Then, while a section's first read of the year is still
     * going, as much more of it as fits in this run. The cursor is saved after each step, so a run that fails or is
     * killed keeps what it did, and the next one starts there.
     *
     * @param  list<string>  $problems
     * @param  array{created: int, updated: int, needsReview: int}  $tally
     */
    private function incrementalRun(SyncRun $run, CarbonImmutable $now, CarbonImmutable $horizon, array &$problems, array &$tally): void
    {
        $nodes = $this->forum->loaNodes();
        $cursor = $this->startingCursor($now, array_keys($nodes));
        // What the last run left to try again; what this run cannot read goes in $retry. A thread left for a section
        // that is no longer configured is dropped with it.
        $pending = $cursor['retry'];
        $retry = [];
        // ELOA returns read in a section still on its first read: its report may be further back, not stored yet. They
        // are applied once this run's pages of that read are stored, and kept to try again until the read is done.
        $returnsRead = [];
        $save = function () use ($run, &$cursor, &$retry, &$returnsRead) {
            $this->syncRuns->saveCursor($run, $this->saved($cursor, $retry, $returnsRead));
        };

        foreach ($nodes as $nodeId => $typeValue) {
            $nodeId = (int) $nodeId;
            $type = LoaType::from($typeValue);
            $readThrough = $cursor['sections'][$nodeId]['readThrough'];
            $wanted = [];
            $complete = true;

            // A section on its very first read has no new posts to look for: its backfill starts at the top.
            if ($readThrough !== null) {
                $cutoff = CarbonImmutable::parse($readThrough)->subHours(self::OVERLAP_HOURS)->max($horizon);
                [$listed, $complete] = $this->listDownTo($nodeId, $cutoff);
                $wanted = array_map(fn () => $type, $listed);

                if (! $complete) {
                    $problems[] = "Section {$nodeId}: stopped at the ".self::MAX_PAGES.'-page limit before reaching the last run';
                }
            }

            foreach (array_keys($pending, $nodeId, true) as $threadId) {
                $wanted[$threadId] = $type;
            }

            $seen = [];
            $items = $this->read($wanted, $nodeId, $now, $problems, $seen, $retry);
            $backfilling = $cursor['sections'][$nodeId]['backfill'] !== null;
            $returns = $this->apply($items, $tally, applyReturns: ! $backfilling);

            if ($backfilling) {
                $returnsRead[$nodeId] = $returns;
            }

            // The new posts are read up to this run's start, whatever failed to fetch (that is on the retry list).
            // Only a listing cut short by the page limit leaves the mark where it was.
            if ($complete) {
                $cursor['sections'][$nodeId]['readThrough'] = $now->toIso8601String();
            }

            $save();
        }

        $started = microtime(true);

        foreach ($nodes as $nodeId => $typeValue) {
            if ($cursor['sections'][(int) $nodeId]['backfill'] !== null) {
                $this->backfill((int) $nodeId, LoaType::from($typeValue), $now, $horizon, $started, $cursor, $retry, $returnsRead, $problems, $tally, $save);
            }
        }

        $save();
    }

    /**
     * Carry one section's first read of the year on from where the last run left it: page by page, older and older,
     * until it reaches the horizon or the forum's last page, or until this run's share of pages or time for it is
     * used. Each page's leave is stored as it is read, with the cursor, so nothing done is lost. Settled leave already
     * stored is not read again. The ELOA returns read in the section this run are applied once its pages are done, so
     * that a report on a later page than its return is stored first.
     *
     * @param  array{sections: array<int, array{readThrough: ?string, backfill: ?array{page: int, reached: ?string, reachedId: ?int}}>, retry: array<int, int>}  $cursor
     * @param  array<int, int>  $retry
     * @param  array<int, list<LoaReturn>>  $returnsRead
     * @param  list<string>  $problems
     * @param  array{created: int, updated: int, needsReview: int}  $tally
     */
    private function backfill(int $nodeId, LoaType $type, CarbonImmutable $now, CarbonImmutable $horizon, float $started, array &$cursor, array &$retry, array &$returnsRead, array &$problems, array &$tally, callable $save): void
    {
        $page = $cursor['sections'][$nodeId]['backfill']['page'];
        $reached = $cursor['sections'][$nodeId]['backfill']['reached'];
        $reached = $reached === null ? null : CarbonImmutable::parse($reached);
        $reachedId = $cursor['sections'][$nodeId]['backfill']['reachedId'] ?? null;
        $resuming = true;

        for ($listed = 0; $listed < self::MAX_PAGES && microtime(true) - $started < self::BACKFILL_SECONDS; $listed++) {
            $this->pause();
            $listing = $this->forum->listLoaThreads($nodeId, $page);
            $first = $listing->threads[0] ?? null;

            // It starts on the page it last read, which now holds what it read there and, after threads were posted
            // since, some it read before. Threads deleted since move the older ones up the listing instead, so that
            // page may now start below threads it never read: step back until it starts no later than where it was.
            if ($resuming && $reached !== null && $page > 1 && $first !== null && $first->postedAt->lessThan($reached)) {
                $page--;

                continue;
            }

            $resuming = false;
            $done = ! $listing->hasMore();
            $wanted = [];

            foreach ($listing->threads as $thread) {
                if ($thread->postedAt->lessThan($horizon)) {
                    $done = true;

                    break;
                }

                // Newer than where it had got to, or the last thread it got to: read already, earlier in this read.
                if ($reached !== null && ($thread->postedAt->greaterThan($reached) || $thread->threadId === $reachedId)) {
                    continue;
                }

                $reached = $thread->postedAt;
                $reachedId = $thread->threadId;

                if ($thread->isVisible()) {
                    $wanted[$thread->threadId] = $type;
                }
            }

            $wanted = array_diff_key($wanted, array_flip($this->settled(array_keys($wanted), $now)));
            $seen = [];
            $items = $this->read($wanted, $nodeId, $now, $problems, $seen, $retry);
            $returnsRead[$nodeId] = [...($returnsRead[$nodeId] ?? []), ...$this->apply($items, $tally, applyReturns: false)];
            $cursor['sections'][$nodeId]['backfill'] = $done ? null : ['page' => $page, 'reached' => $reached?->toIso8601String(), 'reachedId' => $reachedId];
            $save();

            if ($done) {
                break;
            }

            $page++;
        }

        if (($returnsRead[$nodeId] ?? []) !== []) {
            $tally['needsReview'] += $this->loas->sync([], $returnsRead[$nodeId])['unmatchedReturns'];
        }
    }

    /**
     * The cursor this run starts from: the last one saved, fitted to the sections configured now. A section with no
     * state yet (the very first run, or a section added since) starts its first read of the year at the top. A portal
     * that synced before the cursor existed carries on from its last good run, with nothing left to go back for.
     *
     * @param  list<int|string>  $nodeIds
     * @return array{sections: array<int, array{readThrough: ?string, backfill: ?array{page: int, reached: ?string, reachedId: ?int}}>, retry: array<int, int>}
     */
    private function startingCursor(CarbonImmutable $now, array $nodeIds): array
    {
        $saved = $this->syncRuns->latestCursor(SyncRun::SOURCE_LOA);
        $legacy = $saved === null ? $this->syncRuns->latestSuccessful(SyncRun::SOURCE_LOA) : null;
        $sections = [];

        foreach ($nodeIds as $nodeId) {
            $state = $saved['sections'][$nodeId] ?? null;

            if ($state === null && $legacy !== null) {
                $state = ['readThrough' => CarbonImmutable::instance($legacy->started_at)->toIso8601String(), 'backfill' => null];
            }

            $sections[(int) $nodeId] = $state ?? ['readThrough' => null, 'backfill' => ['page' => 1, 'reached' => null, 'reachedId' => null]];
        }

        $retry = [];

        foreach ($saved['retry'] ?? [] as $threadId => $nodeId) {
            $retry[(int) $threadId] = (int) $nodeId;
        }

        return ['sections' => $sections, 'retry' => $retry];
    }

    /**
     * The cursor as it is saved: each section's state; the threads to try again, with the returns read in a section
     * still on its first read (its report may be further back, not stored yet); and, for the freshness note, whether
     * any section is still going back and the oldest post it has reached.
     *
     * @param  array{sections: array<int, array{readThrough: ?string, backfill: ?array{page: int, reached: ?string, reachedId: ?int}}>, retry: array<int, int>}  $cursor
     * @param  array<int, int>  $retry
     * @param  array<int, list<LoaReturn>>  $returnsRead
     * @return array{sections: array<int, array{readThrough: ?string, backfill: ?array{page: int, reached: ?string, reachedId: ?int}}>, retry: array<int, int>, backfilling: bool, reachedBack: ?string}
     */
    private function saved(array $cursor, array $retry, array $returnsRead): array
    {
        $backfilling = array_filter($cursor['sections'], fn (array $section) => $section['backfill'] !== null);
        $reached = null;

        foreach ($backfilling as $nodeId => $section) {
            foreach ($returnsRead[$nodeId] ?? [] as $return) {
                $retry[$return->returnThreadId] = $nodeId;
            }

            $at = $section['backfill']['reached'] === null ? null : CarbonImmutable::parse($section['backfill']['reached']);
            // A section that has not read a page yet has reached nothing, so the oldest reached is today.
            $at ??= CarbonImmutable::now();
            $reached = $reached === null ? $at : $reached->min($at);
        }

        return [
            'sections' => $cursor['sections'],
            'retry' => $retry,
            'backfilling' => $backfilling !== [],
            'reachedBack' => $reached?->toIso8601String(),
        ];
    }

    /**
     * Store what was read, as one transaction, and add it to the run's tally. The ELOA returns among it are applied
     * too, unless the caller holds them back to apply later (see backfill()).
     *
     * @param  list<array{summary: ForumThreadSummary, type: LoaType, parsed: ParsedLoaThread, eventOn: ?CarbonImmutable, eventNote: ?string}>  $items
     * @param  array{created: int, updated: int, needsReview: int}  $tally
     * @return list<LoaReturn> the ELOA returns among them
     */
    private function apply(array $items, array &$tally, bool $applyReturns = true): array
    {
        [$writes, $returns, $review] = $this->build($items, $this->match($items));

        $counts = $this->loas->sync($writes, $applyReturns ? $returns : []);

        $tally['created'] += $counts['created'];
        $tally['updated'] += $counts['updated'];
        $tally['needsReview'] += $review + $counts['unmatchedReturns'];

        return $returns;
    }

    /**
     * Which of these threads are settled history, not worth a forum request to read again (see SETTLED_AFTER_DAYS).
     *
     * @param  list<int>  $threadIds
     * @return list<int>
     */
    private function settled(array $threadIds, CarbonImmutable $now): array
    {
        return $this->loas->settledThreadIds($threadIds, $now->subDays(self::SETTLED_AFTER_DAYS)->startOfDay());
    }

    /**
     * One section's visible threads, newest first, down to the first one older than `$cutoff`. A failure listing the
     * section is not caught: the run fails, with the sections before it already saved.
     *
     * @return array{0: array<int, ForumThreadSummary>, 1: bool} the threads by id, and whether the listing got down to
     *                                                           the cutoff (or the last page) within the page limit
     */
    private function listDownTo(int $nodeId, CarbonImmutable $cutoff): array
    {
        $listed = [];

        for ($page = 1; ; $page++) {
            $this->pause();
            $listing = $this->forum->listLoaThreads($nodeId, $page);

            foreach ($listing->threads as $thread) {
                if ($thread->postedAt->lessThan($cutoff)) {
                    return [$listed, true];
                }

                if ($thread->isVisible()) {
                    $listed[$thread->threadId] = $thread;
                }
            }

            if (! $listing->hasMore()) {
                return [$listed, true];
            }

            if ($page >= self::MAX_PAGES) {
                return [$listed, false];
            }
        }
    }

    /**
     * The troopers each read thread could be for: by forum user id where the post carries one, by the
     * username otherwise. The username is asked for as the same bounded name build() matches on (see fit()).
     *
     * @param  list<array{summary: ForumThreadSummary, type: LoaType, parsed: ParsedLoaThread, eventOn: ?CarbonImmutable, eventNote: ?string}>  $items
     * @return array{byForumId: array<int, list<int>>, byName: array<string, list<int>>}
     */
    private function match(array $items): array
    {
        $usernames = array_map(fn (array $item) => $item['parsed']->username === null ? null : mb_substr($item['parsed']->username, 0, self::RAW_LENGTH), $items);
        $forumIds = array_map(fn (array $item) => $item['parsed']->forumUserId, $items);

        return [
            'byForumId' => $this->troopers->idsByForumUserId(array_values(array_filter($forumIds))),
            'byName' => $this->troopers->idsByUsername(array_values(array_filter($usernames))),
        ];
    }

    /**
     * Fetch and read each wanted thread. One that fails to fetch for now (or whose ELOA date cannot be worked out for
     * now) is noted as a problem and put on `$retry`, under its section, for the next run to read by id; one that is
     * permanently unreadable, or no longer visible, is skipped and taken out of `$seen`.
     *
     * @param  array<int, LoaType>  $wanted  the kind of leave each thread holds, by thread id
     * @param  list<string>  $problems
     * @param  array<int, true>  $seen
     * @param  array<int, int>  $retry  section id by thread id
     * @return list<array{summary: ForumThreadSummary, type: LoaType, parsed: ParsedLoaThread, eventOn: ?CarbonImmutable, eventNote: ?string}>
     */
    private function read(array $wanted, int $nodeId, CarbonImmutable $now, array &$problems, array &$seen, array &$retry): array
    {
        $items = [];
        $pafDates = [];

        foreach ($wanted as $threadId => $type) {
            $this->pause();

            try {
                $thread = $this->forum->fetchLoaThread($threadId);
            } catch (ForumRequestException $e) {
                // Gone for good: nothing a later run could read, so it is not a problem to retry.
                if (! $e->isPermanent()) {
                    $problems[] = "Thread {$threadId}: {$e->getMessage()}";
                    $retry[$threadId] = $nodeId;
                } else {
                    unset($seen[$threadId]);
                }

                continue;
            }

            if (! $thread->summary->isVisible()) {
                unset($seen[$threadId]);

                continue;
            }

            $parsed = $this->parser->parse($thread->summary->title, $thread->firstPostBody, $type, $now);
            $eventOn = null;
            $eventNote = null;

            if (in_array($parsed->kind, [LoaThreadKind::EloaReport, LoaThreadKind::EloaReturn], true) && $parsed->reasons === []) {
                $event = $this->eventDate($parsed, $thread->summary, $pafDates, $problems);

                if ($event === null) {
                    $retry[$threadId] = $nodeId;

                    continue;
                }

                [$eventOn, $eventNote] = $event;
            }

            $items[] = ['summary' => $thread->summary, 'type' => $type, 'parsed' => $parsed, 'eventOn' => $eventOn, 'eventNote' => $eventNote];
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
     * @param  array{byForumId: array<int, list<int>>, byName: array<string, list<int>>}  $troopers  trooper ids by forum user id, and by lower-cased username
     * @return array{0: list<LoaWrite>, 1: list<LoaReturn>, 2: int} writes, returns, how many need a person
     */
    private function build(array $items, array $troopers): array
    {
        $writes = [];
        $returns = [];
        $needsReview = 0;

        foreach ($items as ['summary' => $summary, 'type' => $type, 'parsed' => $parsed, 'eventOn' => $eventOn, 'eventNote' => $eventNote]) {
            $reasons = $parsed->reasons;
            $isReport = $parsed->kind === LoaThreadKind::EloaReport;
            $isReturn = $parsed->kind === LoaThreadKind::EloaReturn;

            // Each raw field is cut to what its column holds before anything is done with it, so one over-long
            // line cannot fail the whole run on a strict database. A field that had to be cut is no longer what
            // the trooper wrote, so the row goes to a person. A return stores no row, so only its name counts.
            $username = $this->fit($parsed->username, 'Username', $reasons);
            $rawStart = $isReturn ? null : $this->fit($isReport ? $eventNote : $parsed->rawStart, 'Start Date', $reasons);
            $rawEnd = $isReturn ? null : $this->fit($parsed->rawEnd, $isReport ? 'Expected return date' : 'End Date', $reasons);

            // A [USER=id] mention is text the poster can edit. Its id and displayed name must
            // identify the same trooper; an unknown name also needs review rather than giving
            // leave to whichever trooper the id names.
            $idMatches = $parsed->forumUserId === null ? [] : ($troopers['byForumId'][$parsed->forumUserId] ?? []);
            $nameMatches = $username === null ? [] : ($troopers['byName'][mb_strtolower($username)] ?? []);
            $identityConflict = count($idMatches) === 1 && ! in_array($idMatches[0], $nameMatches, true);

            if ($identityConflict) {
                $reasons[] = 'The Username does not match the forum user id.';
                $matches = [];
            } else {
                $matches = count($idMatches) === 1 ? $idMatches : $nameMatches;
            }

            if ($username !== null && count($matches) !== 1 && ! $identityConflict) {
                $shown = mb_substr($username, 0, 40);
                $reasons[] = $matches === [] ? "No trooper is named {$shown}." : count($matches)." troopers are named {$shown}.";
            }

            $trooperId = count($matches) === 1 ? $matches[0] : null;

            if ($isReturn) {
                if ($reasons === [] && $trooperId !== null && $eventOn !== null) {
                    $returns[] = new LoaReturn($summary->threadId, $trooperId, $eventOn);
                } else {
                    $needsReview++;
                }

                continue;
            }

            $startsAt = $isReport ? $eventOn : $parsed->startsAt;
            $endsAt = $isReport ? null : $parsed->endsAt;
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
                sourceThread: $this->sourceThread($summary),
                postedAt: $summary->postedAt,
                rawUsername: $username,
                rawStartDate: $rawStart,
                rawEndDate: $rawEnd,
                rawExcerpt: $parsed->excerpt === '' ? null : $parsed->excerpt,
                expectedReturnAt: $clean ? $parsed->expectedReturnAt : null,
                reviewStartsAt: $clean ? null : $parsed->reviewStartsAt,
                reviewEndsAt: $clean ? null : $parsed->reviewEndsAt,
            );
        }

        return [$writes, $returns, $needsReview];
    }

    /**
     * A raw field cut to what its `loas` column holds (and what the username lookup is asked for), with a
     * reason added when it had to be cut. Counted in characters, as the column is.
     *
     * @param  list<string>  $reasons
     */
    private function fit(?string $value, string $label, array &$reasons): ?string
    {
        if ($value === null || mb_strlen($value) <= self::RAW_LENGTH) {
            return $value;
        }

        $reasons[] = "The {$label} is longer than the portal keeps.";

        return mb_substr($value, 0, self::RAW_LENGTH);
    }

    /**
     * Where the thread is, for `source_thread`. The forum's own address for it, unless that would not fit the
     * column, in which case the plain address built from the thread id: it reaches the same thread, and a
     * cut address would not.
     */
    private function sourceThread(ForumThreadSummary $summary): string
    {
        $url = $summary->url;

        return $url !== null && mb_strlen($url) <= self::RAW_LENGTH ? $url : $this->forum->threadUrl($summary->threadId);
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
