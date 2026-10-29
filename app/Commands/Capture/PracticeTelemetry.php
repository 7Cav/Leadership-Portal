<?php

namespace App\Commands\Capture;

use App\Models\Game;
use App\Models\SectionPractice;
use App\Models\SyncRunStatus;
use App\Models\TelemetryInterface;
use App\Repositories\GameRepository;
use App\Repositories\GameServerRepository;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use DateTimeImmutable;

/**
 * What the rollcall screen says about a practice that has no server data (Story 7.9, AD-20, AD-23).
 * Read-only: it decides between "not configured" (the game's env keys are unset, so no capture is
 * attempted), "failed" (the practice's latest capture run found no source that could answer, with
 * when, or broke on the portal's own error, said as such) and nothing (never attempted, or already captured). A source that failed while its fallback
 * answered is not a failure here: the run succeeded, so the practice is captured. One of several
 * training servers that could not be read is (#389): the run failed, and so does the banner.
 *
 * A section's game ({@see gameOf()}) is the game it plays as an Admin set it on the section or the nearest unit above.
 * With none set, it is read from the identities linked to its troopers: the one most of them have,
 * the game added first on a tie (HLL before Arma before WARDOGS). A retired game still counts either way: retiring stops
 * new links, not the capture of the ones made. The retry ({@see RequestPracticeCaptureHandler}) and the rollcall banner
 * both ask here, so they always agree on the game.
 */
class PracticeTelemetry
{
    public function __construct(
        private readonly CaptureSources $sources,
        private readonly SyncRunRepository $syncRuns,
        private readonly TrooperRepository $troopers,
        private readonly TrooperGameIdentityRepository $identities,
        private readonly GameRepository $games,
        private readonly GameServerRepository $servers,
        private readonly UnitRepository $units,
    ) {}

    public function gameOf(int $sectionId): ?Game
    {
        $played = $this->units->gameOf($sectionId);

        if ($played !== null && ($game = $this->games->find($played->gameId)) !== null) {
            return $game;
        }

        $ids = $this->troopers->placedIn($sectionId)->pluck('id')->all();
        $counts = $this->identities->forTroopers($ids)->countBy('game_id');

        $best = null;

        foreach ($this->games->all() as $game) {
            if (($counts[$game->id] ?? 0) > ($counts[$best?->id] ?? 0)) {
                $best = $game;
            }
        }

        return $best;
    }

    public function stateFor(int $sectionId, ?SectionPractice $practice): ?CaptureTelemetry
    {
        if ($practice?->captured_at !== null) {
            return null;
        }

        $game = $this->gameOf($sectionId);

        if ($game !== null && ! $this->sources->isConfigured($game)) {
            // Named apart from missing credentials: an Admin fixes this one by selecting a server, not in env.
            $noServer = $game->interface !== TelemetryInterface::None && ! $this->servers->hasSelectedFor($game) && ! $this->sources->readsEnvServer($game);

            return CaptureTelemetry::notConfigured($game, $noServer);
        }

        $run = $practice === null ? null : $this->syncRuns->latestForPractice('capture', $practice->id);

        if ($run?->status !== SyncRunStatus::Failure) {
            return null;
        }

        $at = DateTimeImmutable::createFromInterface($run->finished_at ?? $run->started_at);

        // The portal's own error is not a server that could not be read.
        if ($run->internal_error) {
            return CaptureTelemetry::internalError($game, $at);
        }

        return CaptureTelemetry::failed($game, $at, $this->feedsOf($game, $run->id));
    }

    /**
     * The feeds the failed run could not get an answer from (#176), so the banner names the one that is down. A run
     * over stored servers recorded each failed read (#389), and those name it exactly, even when another server
     * answered. A run over the legacy env server records none, so its feeds are the game's sources with keys set, in
     * the order a capture tries them: read from the configuration rather than parsed back out of the run's summary
     * text, which is a log line, not a contract.
     *
     * @return list<string>
     */
    private function feedsOf(?Game $game, int $runId): array
    {
        if ($game === null) {
            return [];
        }

        $failed = $this->servers->failedInterfacesOf($runId);

        if ($failed !== []) {
            return $failed;
        }

        $names = [];

        foreach ($this->sources->for($game) as $server) {
            foreach ($server->configuredSources() as $source) {
                $names[$source->name] = true;
            }
        }

        return array_keys($names);
    }
}
