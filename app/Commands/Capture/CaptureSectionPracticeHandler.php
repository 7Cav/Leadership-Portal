<?php

namespace App\Commands\Capture;

use App\Commands\Attendance\MarkAttendanceHandler;
use App\Integration\Crcon\Data\CrconPlayer;
use App\Integration\Crcon\Data\CrconPresence;
use App\Models\GamePlatform;
use App\Models\SectionPractice;
use App\Models\SyncRun;
use App\Repositories\PracticeCapturePlayerRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\AuditEnvelope;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * The capture run (7.5, AD-19): reads the practice window's presence from the platform's first
 * source that answers, stamps `server_ref`/`captured_at`/`capture_source` on the practice, and
 * resolves each observed player through `trooper_game_identities`.
 *
 * - A player with no link is **unmatched**: recorded, surfaced, never assigned to anyone.
 * - A player linked to a trooper whose primary section is not this practice's is surfaced as
 *   **other-section**, never credited (AD-14: attendance counts in the primary section).
 * - If every source raises a typed failure the practice is **left un-captured** and the run is
 *   recorded failed as telemetry-unavailable — never interpreted as zero attendance. The run carries
 *   the practice id, so the rollcall screen can show when it was last tried (7.9). A source whose env
 *   keys are unset is skipped; when none is set nothing is attempted and no run is recorded.
 *
 * The matched troopers are then pre-marked `present`/`auto` through the one attendance writer
 * ({@see MarkAttendanceHandler::preMark()}, 7.6): manual marks win, nothing is ever written as `loa`,
 * other-section and unmatched players get no row. Every run opens a `sync_runs` row
 * (source `capture`) carrying `triggered_by` and the counts: `inserted` = players linked to a
 * trooper, `errored` = unmatched players (so unmatched ones read `partial`), and the summary text.
 * The stamp goes through the audit envelope when a user triggered the run; the audit log's actor is
 * a `users` FK, so a `'system'` run is covered by its `sync_runs` row (AD-16's carve-out for system
 * jobs). No authorization here: callers (scheduler, a later leader trigger) decide who may run it.
 * Re-running re-stamps the same row and re-resolves. The unmatched and other-section players are
 * stored per practice (replaced each run; dismissed ones are stored and filtered on read, so a
 * dismissal can be undone for this practice, #176) for the leader's review (7.7).
 *
 * A practice whose week is locked — the section's weekly report for it has been really sent, not
 * a demo report (#115) — is not captured at all (AD-12: a sent report froze the week's attendance,
 * and an automatic mark is a change like any other). No source is read, nothing is stamped or
 * marked, the run is closed `success` with zero counts and a "week locked" summary, and the result
 * is `CaptureResult::weekLocked()`: an outcome, not an exception, so the queued job neither fails
 * nor retries. A send landing between that check and the pre-mark is caught by the pre-mark's own
 * check and ends the same way.
 */
class CaptureSectionPracticeHandler
{
    public function __construct(
        private readonly CaptureSources $sources,
        private readonly MarkAttendanceHandler $attendance,
        private readonly SectionPracticeRepository $practices,
        private readonly TrooperGameIdentityRepository $identities,
        private readonly TrooperRepository $troopers,
        private readonly PracticeCapturePlayerRepository $capturePlayers,
        private readonly SyncRunRepository $syncRuns,
        private readonly WeeklyReportRepository $reports,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws InvalidArgumentException when the practice does not exist.
     */
    public function handle(CaptureSectionPractice $command): CaptureResult
    {
        $practice = $this->practices->findById($command->sectionPracticeId)
            ?? throw new InvalidArgumentException("Section practice {$command->sectionPracticeId} does not exist.");

        // The window must fall inside the practice's own Cav week: a capture is bounded to the
        // practice, never an arbitrary span stamped onto it.
        if ($command->windowStart < $practice->week_start || $command->windowEnd > $practice->week_start->addWeek()
            || $command->windowEnd < $command->windowStart) {
            throw new InvalidArgumentException("The capture window is not within the week of section practice {$practice->id}.");
        }

        // Sources whose env keys are unset are skipped, not failed (AD-23): with none set there is
        // nothing to attempt, so no run is opened and the practice stays fully manual (7.9). A source
        // that is set but down is the other case, and is recorded below.
        $all = $this->sources->for($command->platform);
        $configured = array_values(array_filter($all, fn (CaptureSource $source) => $source->configured));

        if ($configured === []) {
            return CaptureResult::notConfigured();
        }

        // The job's lock is per practice, so a run of this practice still `in_progress` now was killed
        // before it could record itself. Only this practice's runs are closed: a capture for another
        // practice may be running right now.
        $this->syncRuns->failOrphaned('capture', $practice->id);

        $run = $this->syncRuns->open('capture', $command->triggeredBy, $practice->id);

        try {
            // A week whose report was sent is frozen (AD-12): no source is read and nothing is
            // written. Checked again by the pre-mark itself, for a send landing mid-run.
            if ($this->reports->isSentFor($practice->section_id, $practice->week_start)) {
                return $this->weekLocked($run, $practice);
            }

            $reasons = [];

            foreach ($configured as $source) {
                try {
                    $presence = ($source->fetch)($command->windowStart, $command->windowEnd);
                } catch (CaptureSourceUnavailable $e) {
                    $reasons[] = $e->getMessage();
                    // A source that is down while a fallback answers must not go unnoticed (7.9).
                    Log::warning('Capture source unavailable.', ['sectionPracticeId' => $practice->id, 'source' => $source->name, 'reason' => $e->getMessage()]);

                    continue;
                }

                $result = $this->resolve($presence, $source->name, $command, $practice->section_id);
                $this->persistUnresolved($practice->id, $command->platform, $result);

                // Pre-mark before stamping, so a captured practice implies its marks were written;
                // a failure here retries the whole run, and every write is idempotent.
                $marks = $this->attendance->preMark($practice, array_column($result->matched, 'trooperId'), $command->triggeredBy);

                if ($marks->weekLocked) {
                    return $this->weekLocked($run, $practice);
                }

                $stamp = fn () => $this->practices->stampCapture($practice, $source->serverRef, $presence->fetchedAt, $source->name);
                is_int($command->triggeredBy)
                    ? $this->audit->record($command->triggeredBy, 'practice.captured', null, false, $stamp)
                    : $stamp();

                $this->syncRuns->complete(
                    $run,
                    inserted: $result->matchedCount(),
                    updated: 0,
                    inactivated: 0,
                    errored: $result->unmatchedCount(),
                    error: sprintf(
                        'practice %d, source %s: seen %d, matched %d (%d in another section), unmatched %d; %s',
                        $practice->id, $source->name, $result->seen(), $result->matchedCount(), count($result->otherSection), $result->unmatchedCount(),
                        $marks->confirmed
                            ? 'practice is confirmed, no marks changed'
                            : sprintf('pre-marked %d, left %d untouched (manual/loa)', $marks->marked, $marks->keptManual),
                    ),
                );

                return $result;
            }

            $reasons = $reasons === [] ? ['no source is configured for this game'] : $reasons;
            $this->syncRuns->fail($run, "telemetry unavailable (practice {$practice->id}): ".implode('; ', $reasons));

            return CaptureResult::unavailable($reasons);
        } catch (Throwable $e) {
            // Not a typed seam failure (e.g. a DB error): close the run as failed, then let the
            // job retry — a retry re-stamps and re-resolves, so a stamp that landed first is harmless.
            // The run keeps a plain sentence, not the exception's message: a database error quotes
            // the SQL and the player data it choked on. The exception itself is rethrown, so the
            // worker's log still has the detail.
            $this->syncRuns->fail($run, "the capture hit an unexpected error (practice {$practice->id}, ".class_basename($e).')');

            throw $e;
        }
    }

    /**
     * Keep what the leader must review (7.7): the unmatched and other-section players, replacing
     * the earlier run's. A player a leader already dismissed is stored too, and left out when the
     * list is read ({@see PracticeCapturePlayerRepository::unmatchedFor()}, AD-19): the practice
     * keeps a record of everyone its capture saw, so undoing a dismissal (#176) puts that player
     * straight back on this practice's list, and the drawer can show which of this practice's
     * players are dismissed ({@see PracticeCapturePlayerRepository::dismissedFor()}).
     */
    private function persistUnresolved(int $practiceId, GamePlatform $platform, CaptureResult $result): void
    {
        $this->capturePlayers->replaceForPractice($practiceId, $platform, [
            ...array_map(fn (CrconPlayer $p) => ['platform_id' => $p->platformId, 'player_name' => $p->playerName, 'trooper_id' => null],
                $result->unmatched),
            ...array_map(fn (array $row) => ['platform_id' => $row['player']->platformId, 'player_name' => $row['player']->playerName, 'trooper_id' => $row['trooperId']],
                $result->otherSection),
        ]);
    }

    /**
     * Close the run on a locked week: completed with zero counts, not failed — nothing went wrong,
     * the week is simply frozen, and a failed run would read as telemetry trouble. The summary says
     * why, and the practice is left un-stamped, since no marks were written for it.
     */
    private function weekLocked(SyncRun $run, SectionPractice $practice): CaptureResult
    {
        $this->syncRuns->complete(
            $run,
            inserted: 0,
            updated: 0,
            inactivated: 0,
            errored: 0,
            error: sprintf(
                'practice %d: week locked, the weekly report for the week of %s has been sent; nothing captured, no auto marks written',
                $practice->id, $practice->week_start->toDateString(),
            ),
        );

        return CaptureResult::weekLocked();
    }

    private function resolve(CrconPresence $presence, string $source, CaptureSectionPractice $command, int $sectionId): CaptureResult
    {
        $links = [];
        $unmatched = [];

        foreach ($presence->players as $player) {
            $link = $this->identities->findByPlatformId($command->platform, $player->platformId);

            if ($link === null) {
                $unmatched[] = $player;
            } else {
                $links[] = ['player' => $player, 'trooperId' => $link->trooper_id];
            }
        }

        $troopers = $this->troopers->byIds(array_values(array_unique(array_column($links, 'trooperId'))));
        $matched = [];
        $otherSection = [];

        foreach ($links as $link) {
            // A link whose trooper has left the cache has no section to credit: surfaced, not credited.
            $inSection = $troopers->get($link['trooperId'])?->primary_section_id === $sectionId;
            $inSection ? $matched[] = $link : $otherSection[] = $link;
        }

        return CaptureResult::captured($source, $matched, $otherSection, $unmatched);
    }
}
