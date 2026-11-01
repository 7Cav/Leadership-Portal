<?php

namespace App\Commands\Capture;

use App\Commands\Attendance\MakeupMarkResult;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Commands\Practices\PracticeSchedule;
use App\Commands\Practices\PresenceMinimum;
use App\Integration\Crcon\Data\CrconPlayer;
use App\Integration\Crcon\Data\CrconPresence;
use App\Models\Game;
use App\Models\SectionPractice;
use App\Models\SyncRun;
use App\Models\UnitType;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\GameRepository;
use App\Repositories\GameServerRepository;
use App\Repositories\PracticeCapturePlayerRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\PracticeWindow;
use Carbon\CarbonImmutable;
use DateTimeInterface;
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
 * - A player with no link is **unmatched**: recorded, surfaced, never assigned to anyone. One read through a fallback
 *   that could only give its own player number (BattleMetrics standing in for CRCON) is stored with that marked
 *   (`id_source`), so the rollcall says so and the number is never linked in place of a game ID.
 * - A player linked to a trooper who is not on this practice's rollcall for its week is surfaced as
 *   **other-section**, and never credited (AD-14: a trooper is on one rollcall, their primary
 *   section's; for a week that has ended, the section they ended it in, `TrooperRepository::idsOnRollcall`).
 *   Of a practice read over its slot's hour (#558), one whose time on the server is known and at least the
 *   presence minimum ({@see PresenceMinimum}) is a guest: a `cross_section_sightings` row for this practice, and an
 *   automatic make-up on their own section's rollcall for the week ({@see MarkAttendanceHandler::preMarkMakeups()}),
 *   unless their own section practises in the same window on the server they were seen on (shared slots are
 *   common), which makes it their own practice, captured as such; minutes on a server their own practice does not read
 *   still count towards a make-up. A source that only knows who was seen (no minutes) and a capture of the whole week
 *   (no slot) give no sighting and no make-up: being on a shared server some time does not mean they were at this
 *   practice. A staff member
 *   (HQ staff or a staff department, on no rollcall) is surfaced the same way, and that stored row is what their milpac
 *   lists as seen on the game server (#448, `Milpac\StaffPracticeHistory`).
 * - A player linked to a trooper who has left (no longer serving) is **departed**: stored and listed on the rollcall as
 *   departed, never credited or marked, and not linkable from there (the link exists already).
 * - If any server's every source raises a typed failure the practice is **left un-captured** and the
 *   run is recorded failed as telemetry-unavailable — never interpreted as zero attendance, and never
 *   written as the smaller list the other servers gave. The run carries
 *   the practice id, so the rollcall screen can show when it was last tried (7.9). A source whose env
 *   keys are unset is skipped; when none is set nothing is attempted and no run is recorded.
 *
 * The matched troopers are then pre-marked `present`/`auto` through the one attendance writer
 * ({@see MarkAttendanceHandler::preMark()}, 7.6): manual marks win, nothing is ever written as `loa`,
 * unmatched players get no row anywhere. Over a slot's hour a matched trooper whose minutes are known and under the
 * presence minimum is not pre-marked (#558); one from a source with no minutes is, as before. Every run opens a `sync_runs` row
 * (source `capture`) carrying `triggered_by` and the counts: `inserted` = players linked to a
 * trooper, `updated` = automatic make-ups given, `errored` = unmatched players (so unmatched ones read `partial`), and the summary text.
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
        private readonly PracticeSchedule $schedule,
        private readonly PresenceMinimum $minimum,
        private readonly CrossSectionSightingRepository $sightings,
        private readonly UnitRepository $units,
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

    /**
     * A dry run for the backfill (#558): read the practice's servers and work out who would be pre-marked present and
     * who would get a make-up, writing nothing (no run, no stamp, no server read recorded, no sighting, no mark). The
     * lists are before the attendance guards ({@see MarkAttendanceHandler}): a leader's mark, LOA, `present` and a
     * locked or confirmed week still keep a trooper as they are in a real run. Null when a server could not be read or
     * nothing is configured; an empty answer for a week whose report was sent. `$practice` stands in for a week with no
     * practice row yet (unsaved), so a dry run creates none.
     *
     * @return array{present: list<int>, makeups: list<int>}|null
     */
    public function preview(CaptureSectionPractice $command, ?SectionPractice $practice = null): ?array
    {
        $practice ??= $this->practices->findById($command->sectionPracticeId)
            ?? throw new InvalidArgumentException("Section practice {$command->sectionPracticeId} does not exist.");
        $game = $this->games->find($command->gameId)
            ?? throw new InvalidArgumentException("Game {$command->gameId} does not exist.");

        if (! $this->sources->isConfigured($game)) {
            return null;
        }

        if ($this->reports->isSentFor($practice->section_id, $practice->week_start)) {
            return ['present' => [], 'makeups' => []];
        }

        $answers = [];
        $reasons = [];

        foreach ($this->sources->for($game) as $server) {
            $answer = $this->read($server, $command, $practice->id, null, $reasons);

            if ($answer !== null) {
                $answers[] = $answer;
            }
        }

        if ($reasons !== [] || $answers === []) {
            return null;
        }

        [$presence, $serversOf] = $this->merge($answers);
        $result = $this->resolve($presence, '', $game, $practice, $serversOf);
        $slotted = $this->isSlotWindow($practice, $command);
        $minimum = $this->minimum->forUnit($practice->section_id);
        $attended = $slotted
            ? array_filter($result->matched, fn (array $row) => $row['minutes'] === null || $row['minutes'] >= $minimum)
            : $result->matched;
        $present = array_values(array_unique(array_column($attended, 'trooperId')));
        sort($present);

        return [
            'present' => $present,
            'makeups' => $slotted ? $this->guestsOf($practice, $command, $result->otherSection, $minimum) : [],
        ];
    }

    private function run(CaptureSectionPractice $command): CaptureResult
    {
        $practice = $this->practices->findById($command->sectionPracticeId)
            ?? throw new InvalidArgumentException("Section practice {$command->sectionPracticeId} does not exist.");
        // Read now, not when the run was queued: an Admin may have changed the game's interface since (#388).
        $game = $this->games->find($command->gameId)
            ?? throw new InvalidArgumentException("Game {$command->gameId} does not exist.");

        // The window must fall inside the practice's own Cav week: a capture is bounded to the
        // practice, never an arbitrary span stamped onto it. A scheduled practice belongs to the week of its start, so
        // its hour may run past the week's end (a Friday 2330Z slot, #393), but no further.
        $weekEnd = $practice->week_start->addWeek();
        $latestEnd = $weekEnd->max(CarbonImmutable::instance($command->windowStart)->addMinutes(PracticeWindow::MINUTES));

        if ($command->windowStart < $practice->week_start || $command->windowStart > $weekEnd
            || $command->windowEnd > $latestEnd || $command->windowEnd < $command->windowStart) {
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

            [$presence, $serversOf] = $this->merge($answers);
            $sourceName = implode(',', array_values(array_unique(array_map(fn (array $answer) => $answer['source']->name, $answers))));
            // Every server that answered (#389), cut to the column's width; a practice has a handful at most.
            $serverRef = Str::limit(implode(', ', array_map(fn (array $answer) => $answer['source']->serverRef, $answers)), 250);

            $result = $this->resolve($presence, $sourceName, $game, $practice, $serversOf);
            $this->persistUnresolved($practice->id, $game, $result);

            // Over the slot's hour (#558) a trooper counts once on the server for the minimum; a whole-week read counts
            // anyone seen, as before.
            $slotted = $this->isSlotWindow($practice, $command);
            $minimum = $this->minimum->forUnit($practice->section_id);
            $attended = $slotted
                ? array_values(array_filter($result->matched, fn (array $row) => $row['minutes'] === null || $row['minutes'] >= $minimum))
                : $result->matched;

            // Pre-mark before stamping, so a captured practice implies its marks were written;
            // a failure here retries the whole run, and every write is idempotent.
            $marks = $this->attendance->preMark($practice, array_column($attended, 'trooperId'), $command->triggeredBy);

            if ($marks->weekLocked) {
                return $this->weekLocked($run, $practice);
            }

            $makeups = $slotted ? $this->giveMakeups($practice, $command, $result->otherSection, $minimum) : new MakeupMarkResult;

            $stamp = fn () => $this->practices->stampCapture($practice, $serverRef, $presence->fetchedAt, $sourceName, $command->windowStart, $command->windowEnd);
            is_int($command->triggeredBy)
                ? $this->audit->record($command->triggeredBy, 'practice.captured', null, false, $stamp)
                : $stamp();

            $this->syncRuns->complete(
                $run,
                inserted: $result->matchedCount(),
                updated: $makeups->marked,
                inactivated: 0,
                errored: $result->unmatchedCount(),
                error: sprintf(
                    'practice %d, source %s: seen %d, matched %d (%d in another section, %d departed), unmatched %d; %s',
                    $practice->id, $sourceName, $result->seen(), $result->matchedCount(), count($result->otherSection), count($result->departed), $result->unmatchedCount(),
                    $marks->confirmed
                        ? 'practice is confirmed, no marks changed'
                        : sprintf('pre-marked %d, left %d untouched (manual/loa)', $marks->marked, $marks->keptManual),
                ).($slotted ? sprintf('; minimum %d min, make-ups %d given, %d kept, %d locked', $minimum, $makeups->marked, $makeups->kept, $makeups->locked) : ''),
            );

            return $result;
        } catch (Throwable $e) {
            // Not a typed seam failure (e.g. a DB error): close the run as failed, then let the
            // job retry — a retry re-stamps and re-resolves, so a stamp that landed first is harmless.
            // The run keeps a plain sentence, not the exception's message: a database error quotes
            // the SQL and the player data it choked on. The exception itself is rethrown, so the
            // worker's log still has the detail. Marked internal, so the rollcall does not blame a game server that answered.
            $this->syncRuns->fail($run, "the capture hit an unexpected error (practice {$practice->id}, ".class_basename($e).')', internal: true);

            throw $e;
        }
    }

    /**
     * Read one server: its sources in order, the first that answers being its answer, every attempt on a stored
     * server recorded for the Servers page (#389). A source that is down while the fallback answers is logged, so it
     * does not go unnoticed (7.9). When none answers, its reasons are added to `$reasons` and null is returned. A
     * stored server none of whose sources has credentials cannot be read either; the legacy env server is never in
     * that state here, since a game with no credentials at all is not captured. A dry run ({@see preview()}) passes no
     * `$runId` and records no read.
     *
     * @param  list<string>  $reasons
     * @return array{server: CaptureServer, source: CaptureSource, presence: CrconPresence}|null
     */
    private function read(CaptureServer $server, CaptureSectionPractice $command, int $practiceId, ?int $runId, array &$reasons): ?array
    {
        // The legacy server's reasons stay as they always read ("crcon: unreachable"); a stored one's name the server.
        $prefix = $server->gameServerId === null ? '' : "{$server->label}: ";
        $sources = $server->configuredSources();

        if ($sources === []) {
            $reasons[] = "{$prefix}credentials not set";

            if ($server->gameServerId !== null && $runId !== null) {
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

                if ($source->gameServerId !== null && $runId !== null) {
                    $this->servers->recordRead($source->gameServerId, $runId, false, $e->getMessage());
                }

                continue;
            }

            if ($source->gameServerId !== null && $runId !== null) {
                $this->servers->recordRead($source->gameServerId, $runId, true, null);
            }

            // A service's own player number is the ID every capture of the server reads when that service is the
            // server's own source (an Arma server on BattleMetrics), so it is an ID like any other there. Read through
            // a fallback (CRCON's BattleMetrics twin), it is not the ID the server itself reports, so it stays marked.
            if ($source === ($server->sources[0] ?? null)) {
                $presence = new CrconPresence(
                    array_map(fn (CrconPlayer $p) => $p->idSource === null ? $p : $p->with(clearIdSource: true), $presence->players),
                    $presence->windowStart, $presence->windowEnd, $presence->fetchedAt,
                );
            }

            return ['server' => $server, 'source' => $source, 'presence' => $presence];
        }

        array_push($reasons, ...$failures);

        return null;
    }

    /**
     * One presence from every server's: a player seen on any of them counts once (#389). The latest fetch time
     * stands for the whole read.
     *
     * Two IDs that differ only in letter case are one player: the database compares IDs without case, so keeping both
     * would fail the whole run on the unique key. An Epic ID (32 hex characters, the only kind of ID whose case can
     * differ for the same account) is taken in lower case, the way CRCON reports it and the portal stores it; any other
     * ID keeps the spelling first seen. A Steam ID is digits, so nothing changes for it. A player on two servers has
     * their minutes added, capped at the window (#558); unknown minutes add nothing. Which server each player was seen on
     * is kept beside it, with their minutes there, keyed by the merged player's ID in lower case: a shared slot only
     * counts as their own practice on a server their own section reads ({@see guestsOf()}).
     *
     * @param  list<array{server: CaptureServer, source: CaptureSource, presence: CrconPresence}>  $answers
     * @return array{0: CrconPresence, 1: array<string, array<string, int|null>>}
     */
    private function merge(array $answers): array
    {
        $presences = array_column($answers, 'presence');

        /** @var array<string, CrconPlayer> $byPlatformId */
        $byPlatformId = [];
        /** @var array<string, array<string, int|null>> $serversOf */
        $serversOf = [];

        $cap = self::windowMinutes($presences[0]->windowStart, $presences[0]->windowEnd);

        foreach ($answers as $answer) {
            $server = self::serverKey($answer['server']);

            foreach ($answer['presence']->players as $player) {
                $id = preg_match('/^[0-9a-f]{32}$/i', $player->platformId) === 1 ? strtolower($player->platformId) : $player->platformId;
                $key = mb_strtolower($id);
                $seen = $byPlatformId[$key] ?? null;
                $serversOf[$key][$server] = array_key_exists($server, $serversOf[$key] ?? [])
                    ? self::addMinutes($serversOf[$key][$server], $player->minutes, $cap)
                    : $player->minutes;

                if ($seen === null) {
                    $byPlatformId[$key] = $id === $player->platformId ? $player : $player->with(platformId: $id);

                    continue;
                }

                // On two servers in the window (#558): their minutes add up, never past the window itself.
                $minutes = self::addMinutes($seen->minutes, $player->minutes, $cap);

                if ($minutes !== $seen->minutes) {
                    $byPlatformId[$key] = new CrconPlayer($seen->playerName, $seen->platformId, $seen->idSource, $minutes);
                }
            }
        }

        $fetchedAt = max(array_map(fn (CrconPresence $presence) => $presence->fetchedAt, $presences));

        return [new CrconPresence(array_values($byPlatformId), $presences[0]->windowStart, $presences[0]->windowEnd, $fetchedAt), $serversOf];
    }

    /** One server's key: the stored server's id, or for the legacy env server the reference of its own source. */
    private static function serverKey(CaptureServer $server): string
    {
        return $server->gameServerId !== null ? "server:{$server->gameServerId}" : ($server->sources[0]->serverRef ?? $server->label);
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
            ...array_map(fn (CrconPlayer $p) => ['platform_id' => $p->platformId, 'player_name' => $p->playerName, 'trooper_id' => null, 'id_source' => $p->idSource],
                $result->unmatched),
            ...array_map(fn (array $row) => ['platform_id' => $row['player']->platformId, 'player_name' => $row['player']->playerName, 'trooper_id' => $row['trooperId'], 'id_source' => $row['player']->idSource],
                [...$result->otherSection, ...$result->departed]),
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

    /**
     * @param  array<string, array<string, int|null>>  $serversOf  each player's minutes per server, from {@see merge()}
     */
    private function resolve(CrconPresence $presence, string $source, Game $game, SectionPractice $practice, array $serversOf = []): CaptureResult
    {
        $links = [];
        $unmatched = [];

        // Every player's link in one read (audit 2, L7), matched back as the database matched it: the row with the very
        // spelling first, else the one it found without case (MySQL compares IDs that way, and the merge keeps one
        // player per ID regardless of case).
        $rows = $this->identities->findByPlatformIds($game, array_map(fn (CrconPlayer $player) => $player->platformId, $presence->players));
        $exact = $rows->keyBy('platform_id');
        $folded = $rows->keyBy(fn ($row) => mb_strtolower($row->platform_id));

        foreach ($presence->players as $player) {
            $link = $exact->get($player->platformId) ?? $folded->get(mb_strtolower($player->platformId));

            if ($link === null) {
                $unmatched[] = $player;
            } else {
                $links[] = ['player' => $player, 'trooperId' => $link->trooper_id, 'minutes' => $player->minutes, 'servers' => $serversOf[mb_strtolower($player->platformId)] ?? []];
            }
        }

        // On the practice's rollcall for its week: placed in the section now, or for a week that has ended, on the
        // roster it ended with (the 2026-10-29 audit), so a capture of the closing week credits that week's troopers.
        $linked = array_values(array_unique(array_column($links, 'trooperId')));
        $troopers = $this->troopers->byIds($linked);
        $onRollcall = $linked === [] ? [] : array_flip($this->troopers->idsOnRollcall($practice->section_id, $practice->week_start, $linked, serving: true)->all());
        $matched = [];
        $otherSection = [];
        $departed = [];

        // A trooper seen under two linked IDs counts once for the minimum (#558): their minutes add up, capped at the window.
        $cap = self::windowMinutes($presence->windowStart, $presence->windowEnd);
        $minutesOf = [];
        $serversOfTrooper = [];

        foreach ($links as $link) {
            $minutesOf[$link['trooperId']] = array_key_exists($link['trooperId'], $minutesOf)
                ? self::addMinutes($minutesOf[$link['trooperId']], $link['minutes'], $cap)
                : $link['minutes'];

            foreach ($link['servers'] as $server => $minutes) {
                $serversOfTrooper[$link['trooperId']][$server] = array_key_exists($server, $serversOfTrooper[$link['trooperId']] ?? [])
                    ? self::addMinutes($serversOfTrooper[$link['trooperId']][$server], $minutes, $cap)
                    : $minutes;
            }
        }

        $links = array_map(fn (array $link) => ['minutes' => $minutesOf[$link['trooperId']], 'servers' => $serversOfTrooper[$link['trooperId']] ?? []] + $link, $links);

        foreach ($links as $link) {
            $trooper = $troopers->get($link['trooperId']);

            // On the week's rollcall first: a trooper who served the closing week is credited there though they have left
            // since. Otherwise one who has left keeps their last placement, so they are listed as departed, never credited.
            if (isset($onRollcall[$link['trooperId']])) {
                $matched[] = $link;
            } elseif ($trooper === null || ! $trooper->status->isServing()) {
                $departed[] = $link;
            } else {
                $otherSection[] = $link;
            }
        }

        return CaptureResult::captured($source, $matched, $otherSection, $unmatched, $departed);
    }

    /**
     * Whether the run reads the practice's own scheduled hour (#558): the section has a slot placed in this week (or the
     * week's move) and the run asks for exactly it. Only then do minutes count and make-ups follow; a "Try again" over the week so far does neither.
     */
    private function isSlotWindow(SectionPractice $practice, CaptureSectionPractice $command): bool
    {
        $window = $this->schedule->scheduledWindow($practice->section_id, $practice->week_start, $practice);

        return $window !== null
            && $window->start->getTimestamp() === $command->windowStart->getTimestamp()
            && $window->end->getTimestamp() === $command->windowEnd->getTimestamp();
    }

    /**
     * The automatic make-ups of a slotted practice (#558): each trooper from another section who was on the server for
     * at least `$minimum` minutes, with known minutes, placed in a section of their own whose practice this week is not
     * in the same window on a server it reads. Each gets a `cross_section_sightings` row for this practice (first sighting wins) and then a
     * make-up through {@see MarkAttendanceHandler::preMarkMakeups()}, which keeps a leader's mark, `present`, LOA, a
     * locked week and a confirmed practice as they are. Staff (on no section) get neither.
     *
     * @param  list<array{player: CrconPlayer, trooperId: int, minutes: int|null, servers: array<string, int|null>}>  $otherSection
     */
    private function giveMakeups(SectionPractice $host, CaptureSectionPractice $command, array $otherSection, int $minimum): MakeupMarkResult
    {
        $guests = $this->guestsOf($host, $command, $otherSection, $minimum);

        if ($guests === []) {
            return new MakeupMarkResult;
        }

        $this->sightings->record($host, $guests, $command->windowStart, $command->windowEnd);

        return $this->attendance->preMarkMakeups($host, $guests, $command->triggeredBy);
    }

    /**
     * Who of `$otherSection` was a guest at `$host` ({@see giveMakeups()}), in id order.
     *
     * @param  list<array{player: CrconPlayer, trooperId: int, minutes: int|null, servers: array<string, int|null>}>  $otherSection
     * @return list<int>
     */
    private function guestsOf(SectionPractice $host, CaptureSectionPractice $command, array $otherSection, int $minimum): array
    {
        $eligible = array_filter($otherSection, fn (array $row) => $row['minutes'] !== null && $row['minutes'] >= $minimum);
        $ids = array_values(array_unique(array_column($eligible, 'trooperId')));
        $serversOf = array_column($eligible, 'servers', 'trooperId');
        $cap = self::windowMinutes($command->windowStart, $command->windowEnd);

        if ($ids === []) {
            return [];
        }

        $guests = [];

        foreach ($this->troopers->ownSectionsInWeek($ids, $host->week_start) as $trooperId => $sectionId) {
            if ($sectionId === $host->section_id || $this->units->find($sectionId)?->type !== UnitType::Section) {
                continue;
            }

            // Their own section practises in the same window (a shared slot): time on a server their own practice reads
            // was their own practice, not a make-up. Only their minutes on the host's other servers count towards one.
            $own = $this->schedule->scheduledWindow($sectionId, $host->week_start);

            if ($own !== null && $own->start->getTimestamp() < $command->windowEnd->getTimestamp()
                && $own->end->getTimestamp() > $command->windowStart->getTimestamp()) {
                $ownServers = array_flip($this->serversReadBy($sectionId));
                $elsewhere = null;

                foreach ($serversOf[$trooperId] ?? [] as $server => $minutes) {
                    if (! isset($ownServers[$server])) {
                        $elsewhere = self::addMinutes($elsewhere, $minutes, $cap);
                    }
                }

                if ($elsewhere === null || $elsewhere < $minimum) {
                    continue;
                }
            }

            $guests[] = $trooperId;
        }

        sort($guests);

        return $guests;
    }

    /**
     * The keys of the servers a capture of the section's practice reads: the training servers of its game in effect.
     *
     * @return list<string>
     */
    private function serversReadBy(int $sectionId): array
    {
        $gameId = $this->units->gameOf($sectionId)?->gameId;
        $game = $gameId === null ? null : $this->games->find($gameId);

        return $game === null ? [] : array_map(self::serverKey(...), $this->sources->for($game));
    }

    private static function windowMinutes(DateTimeInterface $start, DateTimeInterface $end): int
    {
        return intdiv(max(0, $end->getTimestamp() - $start->getTimestamp()), 60);
    }

    /** Two known minute counts added and capped; an unknown one adds nothing, and two unknown stay unknown. */
    private static function addMinutes(?int $a, ?int $b, int $cap): ?int
    {
        if ($a === null && $b === null) {
            return null;
        }

        return min($cap, ($a ?? 0) + ($b ?? 0));
    }
}
