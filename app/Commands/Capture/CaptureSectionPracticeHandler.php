<?php

namespace App\Commands\Capture;

use App\Integration\Crcon\Data\CrconPresence;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\TrooperRepository;
use App\Support\Audit\AuditEnvelope;
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
 *   recorded failed as telemetry-unavailable — never interpreted as zero attendance.
 *
 * Pre-marking attendance is 7.6; this only stamps and resolves. Every run opens a `sync_runs` row
 * (source `capture`) carrying `triggered_by` and the counts: `inserted` = players linked to a
 * trooper, `errored` = unmatched players (so unmatched ones read `partial`), and the summary text.
 * The stamp goes through the audit envelope when a user triggered the run; the audit log's actor is
 * a `users` FK, so a `'system'` run is covered by its `sync_runs` row (AD-16's carve-out for system
 * jobs). No authorization here: callers (scheduler, a later leader trigger) decide who may run it.
 * Re-running re-stamps the same row and re-resolves.
 */
class CaptureSectionPracticeHandler
{
    public function __construct(
        private readonly CaptureSources $sources,
        private readonly SectionPracticeRepository $practices,
        private readonly TrooperGameIdentityRepository $identities,
        private readonly TrooperRepository $troopers,
        private readonly SyncRunRepository $syncRuns,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws InvalidArgumentException when the practice does not exist.
     */
    public function handle(CaptureSectionPractice $command): CaptureResult
    {
        $practice = $this->practices->findById($command->sectionPracticeId)
            ?? throw new InvalidArgumentException("Section practice {$command->sectionPracticeId} does not exist.");

        $run = $this->syncRuns->open('capture', $command->triggeredBy);

        try {
            $reasons = [];

            foreach ($this->sources->for($command->platform) as $source) {
                try {
                    $presence = ($source->fetch)($command->windowStart, $command->windowEnd);
                } catch (CaptureSourceUnavailable $e) {
                    $reasons[] = $e->getMessage();

                    continue;
                }

                $result = $this->resolve($presence, $source->name, $command, $practice->section_id);

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
                        'source %s: seen %d, matched %d (%d in another section), unmatched %d',
                        $source->name, $result->seen(), $result->matchedCount(), count($result->otherSection), $result->unmatchedCount(),
                    ),
                );

                return $result;
            }

            $reasons = $reasons === [] ? ['no source is configured for this game'] : $reasons;
            $this->syncRuns->fail($run, 'telemetry unavailable: '.implode('; ', $reasons));

            return CaptureResult::unavailable($reasons);
        } catch (Throwable $e) {
            // Not a typed seam failure (e.g. a DB error): close the run as failed, then let the
            // job retry — the practice stays as it was.
            $this->syncRuns->fail($run, $e->getMessage());

            throw $e;
        }
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
