<?php

namespace App\Commands\Capture;

use App\Models\GamePlatform;
use App\Models\SectionPractice;
use App\Models\SyncRunStatus;
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
 * its troopers: the one most of them have, HLL before Arma before WARDOGS on a tie.
 */
class PracticeTelemetry
{
    public function __construct(
        private readonly CaptureSources $sources,
        private readonly SyncRunRepository $syncRuns,
        private readonly TrooperRepository $troopers,
        private readonly TrooperGameIdentityRepository $identities,
    ) {}

    public function platformOf(int $sectionId): ?GamePlatform
    {
        $ids = $this->troopers->placedIn($sectionId)->pluck('id')->all();
        $counts = $this->identities->forTroopers($ids)->countBy(fn ($identity) => $identity->platform->value);

        $best = null;

        foreach (GamePlatform::cases() as $platform) {
            if (($counts[$platform->value] ?? 0) > ($counts[$best?->value] ?? 0)) {
                $best = $platform;
            }
        }

        return $best;
    }

    public function stateFor(int $sectionId, ?SectionPractice $practice): ?CaptureTelemetry
    {
        if ($practice?->captured_at !== null) {
            return null;
        }

        $platform = $this->platformOf($sectionId);

        if ($platform !== null && ! $this->sources->isConfigured($platform)) {
            return CaptureTelemetry::notConfigured($platform);
        }

        $run = $practice === null ? null : $this->syncRuns->latestForPractice('capture', $practice->id);

        if ($run?->status !== SyncRunStatus::Failure) {
            return null;
        }

        return CaptureTelemetry::failed($platform, DateTimeImmutable::createFromInterface($run->finished_at ?? $run->started_at));
    }
}
