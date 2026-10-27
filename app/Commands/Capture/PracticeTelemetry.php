<?php

namespace App\Commands\Capture;

use App\Models\Game;
use App\Models\SectionPractice;
use App\Models\SyncRunStatus;
use App\Repositories\GameRepository;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\TrooperRepository;
use DateTimeImmutable;

/**
 * What the rollcall screen says about a practice that has no server data (Story 7.9, AD-20, AD-23).
 * Read-only: it decides between "not configured" (the game's env keys are unset, so no capture is
 * attempted), "failed" (the practice's latest capture run found no source that could answer, with
 * when) and nothing (never attempted, or already captured). A source that failed while another
 * answered is not a failure here: the run succeeded, so the practice is captured.
 *
 * A section has no game of its own in the data, so its game is read from the identities linked to
 * its troopers: the one most of them have, the game added first on a tie (HLL before Arma before
 * WARDOGS). A retired game still counts: retiring stops new links, not the capture of the ones made.
 */
class PracticeTelemetry
{
    public function __construct(
        private readonly CaptureSources $sources,
        private readonly SyncRunRepository $syncRuns,
        private readonly TrooperRepository $troopers,
        private readonly TrooperGameIdentityRepository $identities,
        private readonly GameRepository $games,
    ) {}

    public function gameOf(int $sectionId): ?Game
    {
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
            return CaptureTelemetry::notConfigured($game);
        }

        $run = $practice === null ? null : $this->syncRuns->latestForPractice('capture', $practice->id);

        if ($run?->status !== SyncRunStatus::Failure) {
            return null;
        }

        return CaptureTelemetry::failed($game, DateTimeImmutable::createFromInterface($run->finished_at ?? $run->started_at), $this->feedsOf($game));
    }

    /**
     * The game's sources with keys set, in the order a capture tries them: the ones the failed run
     * read and could not get an answer from (#176). Read from the configuration rather than parsed
     * back out of the run's summary text, which is a log line, not a contract. A key changed since
     * the run is the one case they differ, and the next run follows the new keys anyway.
     *
     * @return list<string>
     */
    private function feedsOf(?Game $game): array
    {
        if ($game === null) {
            return [];
        }

        return array_values(array_map(
            fn (CaptureSource $source) => $source->name,
            array_filter($this->sources->for($game), fn (CaptureSource $source) => $source->configured),
        ));
    }
}
