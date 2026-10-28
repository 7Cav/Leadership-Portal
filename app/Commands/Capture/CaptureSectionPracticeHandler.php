<?php

namespace App\Commands\Capture;

use App\Commands\Attendance\MarkAttendanceHandler;
use App\Integration\Crcon\Data\CrconPlayer;
use App\Integration\Crcon\Data\CrconPresence;
use App\Models\Game;
use App\Models\SectionPractice;
use App\Models\SyncRun;
use App\Repositories\GameRepository;
use App\Repositories\GameServerRepository;
use App\Repositories\PracticeCapturePlayerRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The capture run (7.5, AD-19): reads the practice window's presence from every training server of
 * the game ({@see CaptureSources}, #389), each through its first source that answers, merges them (a
 * player seen on any server counts), stamps `server_ref` (every server that answered),
 * `captured_at` and `capture_source` on the practice, and resolves each observed player through
 * `trooper_game_identities`.
 *
 * - A player with no link is **unmatched**: recorded, surfaced, never assigned to anyone.
 * - A player linked to a trooper whose primary section is not this practice's is surfaced as
 *   **other-section**, and never credited (AD-14: a trooper is on one rollcall, their primary
 *   section's). The capture does not give them a makeup on their own section's rollcall either, and
 *   records no `cross_section_sightings` row: until the portal has practice time slots, the capture
 *   window is the whole Cav week so far and the game servers are shared by every section, so being
 *   seen does not mean they were at this practice (decided 2026-10-23). Makeups come from a leader.
 * - If any server's every source raises a typed failure the practice is **left un-captured** and the
 *   run is recorded failed as telemetry-unavailable — never interpreted as zero attendance, and never
 *   written as the smaller list the other servers gave. The run carries
 *   the practice id, so the rollcall screen can show when it was last tried (7.9). A source whose env
 *   keys are unset is skipped; when none is set nothing is attempted and no run is recorded.
 *
 * The matched troopers are then pre-marked `present`/`auto` through the one attendance writer
 * ({@see MarkAttendanceHandler::preMark()}, 7.6): manual marks win, nothing is ever written as `loa`,
 * other-section and unmatched players get no row anywhere. Every run opens a `sync_runs` row
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
        private readonly GameRepository $games,
        private readonly GameServerRepository $servers,
        private readonly ActingFor $acting,
    ) {}

    /**
     * Runs the capture as whoever really queued it: a "Try again" made while acting as someone carries that in
     * `$command->acting`, held again here, so the run's entries name the Admin or leader on the person's behalf (or in
     * the billet's place) and its `sync_runs` row names them too. What was held before is put back afterwards, since a
     * job run on the sync queue shares the request's instance.
     *
     * @throws InvalidArgumentException when the practice or the game does not exist.
     */
    public function handle(CaptureSectionPractice $command): CaptureResult
    {
        $before = $this->acting->snapshot();
        $this->acting->restore($command->acting);

        try {
            return $this->run($command);
        } finally {
            $this->acting->restore($before);
        }
    }

    private function run(CaptureSectionPractice $command): CaptureResult
    {
        $practice = $this->practices->findById($command->sectionPracticeId)
            ?? throw new InvalidArgumentException("Section practice {$command->sectionPracticeId} does not exist.");
        // Read now, not when the run was queued: an Admin may have changed the game's interface since (#388).
        $game = $this->games->find($command->gameId)
            ?? throw new InvalidArgumentException("Game {$command->gameId} does not exist.");

        // The window must fall inside the practice's own Cav week: a capture is bounded to the
        // practice, never an arbitrary span stamped onto it.
        if ($command->windowStart < $practice->week_start || $command->windowEnd > $practice->week_start->addWeek()
            || $command->windowEnd < $command->windowStart) {
            throw new InvalidArgumentException("The capture window is not within the week of section practice {$practice->id}.");
        }

        // Sources whose env keys are unset are skipped, not failed (AD-23): with none set on any server there is
        // nothing to attempt, so no run is opened and the practice stays fully manual (7.9). A source that is set
        // but down is the other case, and is recorded below; so is a training server none of whose sources is set
        // while another's is, since reading only the others would under-report (#389).
        $servers = $this->sources->for($game);

        if (! $this->sources->isConfigured($game)) {
            return CaptureResult::notConfigured();
        }

        // The job's lock is per practice, so a run of this practice still `in_progress` now was killed
        // before it could record itself. Only this practice's runs are closed: a capture for another
        // practice may be running right now.
        $this->syncRuns->failOrphaned('capture', $practice->id);

        // The run names who really asked for it, as the audit log does: the Admin or leader, not the person acted as.
        $runBy = is_int($command->triggeredBy) ? $this->acting->attribute($command->triggeredBy)[0] : $command->triggeredBy;
        $run = $this->syncRuns->open('capture', $runBy, $practice->id);

        try {
            // A week whose report was sent is frozen (AD-12): no source is read and nothing is
            // written. Checked again by the pre-mark itself, for a send landing mid-run.
            if ($this->reports->isSentFor($practice->section_id, $practice->week_start)) {
                return $this->weekLocked($run, $practice);
            }

            // Every server is read, even after one has failed, so each one's last read is current on the Servers page.
            $answers = [];
            $reasons = [];

            foreach ($servers as $server) {
                $answer = $this->read($server, $command, $practice->id, $run->id, $reasons);

                if ($answer !== null) {
                    $answers[] = $answer;
                }
            }

            // One server that could not be read leaves the practice un-captured: the others' list would be smaller
            // than who was there, and a smaller list reads as absence (#389).
            if ($reasons !== [] || $answers === []) {
                $reasons = $reasons === [] ? ['no source is configured for this game'] : $reasons;
                $this->syncRuns->fail($run, "telemetry unavailable (practice {$practice->id}): ".implode('; ', $reasons));

                return CaptureResult::unavailable($reasons);
            }

            $presence = $this->merge(array_column($answers, 'presence'));
            $sourceName = implode(',', array_values(array_unique(array_map(fn (array $answer) => $answer['source']->name, $answers))));
            // Every server that answered (#389), cut to the column's width; a practice has a handful at most.
            $serverRef = Str::limit(implode(', ', array_map(fn (array $answer) => $answer['source']->serverRef, $answers)), 250);

            $result = $this->resolve($presence, $sourceName, $game, $practice->section_id);
            $this->persistUnresolved($practice->id, $game, $result);

            // Pre-mark before stamping, so a captured practice implies its marks were written;
            // a failure here retries the whole run, and every write is idempotent.
            $marks = $this->attendance->preMark($practice, array_column($result->matched, 'trooperId'), $command->triggeredBy);

            if ($marks->weekLocked) {
                return $this->weekLocked($run, $practice);
            }

            $stamp = fn () => $this->practices->stampCapture($practice, $serverRef, $presence->fetchedAt, $sourceName);
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
                    $practice->id, $sourceName, $result->seen(), $result->matchedCount(), count($result->otherSection), $result->unmatchedCount(),
                    $marks->confirmed
                        ? 'practice is confirmed, no marks changed'
                        : sprintf('pre-marked %d, left %d untouched (manual/loa)', $marks->marked, $marks->keptManual),
                ),
            );

            return $result;
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
     * Read one server: its sources in order, the first that answers being its answer, every attempt on a stored
     * server recorded for the Servers page (#389). A source that is down while the fallback answers is logged, so it
     * does not go unnoticed (7.9). When none answers, its reasons are added to `$reasons` and null is returned. A
     * stored server none of whose sources has credentials cannot be read either; the legacy env server is never in
     * that state here, since a game with no credentials at all is not captured.
     *
     * @param  list<string>  $reasons
     * @return array{source: CaptureSource, presence: CrconPresence}|null
     */
    private function read(CaptureServer $server, CaptureSectionPractice $command, int $practiceId, int $runId, array &$reasons): ?array
    {
        // The legacy server's reasons stay as they always read ("crcon: unreachable"); a stored one's name the server.
        $prefix = $server->gameServerId === null ? '' : "{$server->label}: ";
        $sources = $server->configuredSources();

        if ($sources === []) {
            $reasons[] = "{$prefix}credentials not set";

            if ($server->gameServerId !== null) {
                $this->servers->recordRead($server->gameServerId, $runId, false, 'credentials not set');
            }

            return null;
        }

        $failures = [];

        foreach ($sources as $source) {
            try {
                $presence = ($source->fetch)($command->windowStart, $command->windowEnd);
            } catch (CaptureSourceUnavailable $e) {
                $failures[] = $prefix.$e->getMessage();
                Log::warning('Capture source unavailable.', ['sectionPracticeId' => $practiceId, 'source' => $source->name, 'server' => $server->label, 'reason' => $e->getMessage()]);

                if ($source->gameServerId !== null) {
                    $this->servers->recordRead($source->gameServerId, $runId, false, $e->getMessage());
                }

                continue;
            }

            if ($source->gameServerId !== null) {
                $this->servers->recordRead($source->gameServerId, $runId, true, null);
            }

            return ['source' => $source, 'presence' => $presence];
        }

        array_push($reasons, ...$failures);

        return null;
    }

    /**
     * One presence from every server's: a player seen on any of them counts once (#389). The latest fetch time
     * stands for the whole read.
     *
     * @param  list<CrconPresence>  $presences
     */
    private function merge(array $presences): CrconPresence
    {
        if (count($presences) === 1) {
            return $presences[0];
        }

        /** @var array<string, CrconPlayer> $byPlatformId */
        $byPlatformId = [];

        foreach ($presences as $presence) {
            foreach ($presence->players as $player) {
                $byPlatformId[$player->platformId] ??= $player;
            }
        }

        $fetchedAt = max(array_map(fn (CrconPresence $presence) => $presence->fetchedAt, $presences));

        return new CrconPresence(array_values($byPlatformId), $presences[0]->windowStart, $presences[0]->windowEnd, $fetchedAt);
    }

    /**
     * Keep what the leader must review (7.7): the unmatched and other-section players, replacing
     * the earlier run's. A player a leader already dismissed is stored too, and left out when the
     * list is read ({@see PracticeCapturePlayerRepository::unmatchedFor()}, AD-19): the practice
     * keeps a record of everyone its capture saw, so undoing a dismissal (#176) puts that player
     * straight back on this practice's list, and the drawer can show which of this practice's
     * players are dismissed ({@see PracticeCapturePlayerRepository::dismissedFor()}).
     */
    private function persistUnresolved(int $practiceId, Game $game, CaptureResult $result): void
    {
        $this->capturePlayers->replaceForPractice($practiceId, $game, [
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

    private function resolve(CrconPresence $presence, string $source, Game $game, int $sectionId): CaptureResult
    {
        $links = [];
        $unmatched = [];

        foreach ($presence->players as $player) {
            $link = $this->identities->findByPlatformId($game, $player->platformId);

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
