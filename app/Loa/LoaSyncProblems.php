<?php

namespace App\Loa;

use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Repositories\SyncRunRepository;
use Carbon\CarbonImmutable;

/**
 * What went wrong in the latest finished LOA sync, for an Admin (2026-10-29): the threads it could not read, how many
 * posts it read but could not make sense of (`needs_review`), and why a failed run stopped. Read-only over what the sync
 * already records in `sync_runs` (AD-20); nothing here calls the forum. The sync stores its problems as one text, each
 * "Thread <id>: <why>" joined by "; " and cut at 2000 characters, so they are split back apart here, the thread id kept
 * for a link to the post.
 */
final class LoaSyncProblems
{
    /** Most problems listed; the count says how many the run had. */
    public const LIMIT = 20;

    public function __construct(private readonly SyncRunRepository $syncRuns) {}

    /**
     * Null when no LOA sync has finished yet.
     *
     * @return array{status: string, finishedAt: ?string, unread: int, needsReview: int, failure: ?string, problems: list<array{threadId: ?int, text: string}>}|null
     */
    public function latest(): ?array
    {
        $run = $this->syncRuns->latestFinished(SyncRun::SOURCE_LOA);

        if ($run === null) {
            return null;
        }

        $failed = $run->status === SyncRunStatus::Failure;
        $problems = $failed || $run->error === null ? [] : array_values(array_filter(array_map('trim', explode('; ', $run->error)), fn (string $text) => $text !== ''));

        return [
            'status' => $run->status->value,
            'finishedAt' => $run->finished_at ? CarbonImmutable::instance($run->finished_at)->toIso8601String() : null,
            'unread' => (int) $run->errored,
            'needsReview' => (int) $run->needs_review,
            // A failed run records why it stopped, not a list of threads.
            'failure' => $failed ? mb_substr((string) $run->error, 0, 300) : null,
            'problems' => array_map(fn (string $text) => [
                'threadId' => preg_match('/^Thread (\d+):/', $text, $match) === 1 ? (int) $match[1] : null,
                'text' => mb_substr($text, 0, 300),
            ], array_slice($problems, 0, self::LIMIT)),
        ];
    }
}
