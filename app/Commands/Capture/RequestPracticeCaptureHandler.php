<?php

namespace App\Commands\Capture;

use App\Authorization\ScopeResolver;
use App\Commands\Attendance\InvalidAttendanceMarkException;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Validates a leader's "Try again" (Story 7.9) and answers the capture to queue: the section's
 * practice for the week, read for the part of that week that has happened so far, as the actor.
 * The controller queues it; the per-practice lock on the job (7.5) still keeps runs from
 * overlapping. Refuses a section outside the actor's scope (AD-8), a game with no source set up
 * (nothing would be attempted), and a second request for the same practice within a minute. The
 * practice row is created if the week has none yet, as an audited action (AD-16).
 *
 * The practice has no schedule of its own in the data, so its window is the Cav week up to now:
 * the widest span the capture run accepts, and the leader reviews what it marks.
 */
class RequestPracticeCaptureHandler
{
    /** Seconds before the same practice may be requested again. */
    public const THROTTLE_SECONDS = 60;

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly SectionPracticeRepository $practices,
        private readonly PracticeTelemetry $telemetry,
        private readonly CaptureSources $sources,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws CaptureRetryForbiddenException when the section is outside the actor's scope.
     * @throws InvalidAttendanceMarkException when the unit is not a section.
     * @throws CaptureRetryRefusedException when no source can be attempted for the section's game.
     * @throws CaptureRetryThrottledException when the practice was requested a moment ago.
     */
    public function handle(User $actor, RequestPracticeCapture $command): CaptureSectionPractice
    {
        if (! $this->scope->resolve($actor)->contains($command->sectionId)) {
            throw new CaptureRetryForbiddenException($command->sectionId);
        }

        if ($this->units->find($command->sectionId)?->type !== UnitType::Section) {
            throw InvalidAttendanceMarkException::notASection($command->sectionId);
        }

        $platform = $this->telemetry->platformOf($command->sectionId)
            ?? throw CaptureRetryRefusedException::noGameLinked();

        if (! $this->sources->isConfigured($platform)) {
            throw CaptureRetryRefusedException::notConfigured($platform);
        }

        $weekStart = WeekOf::start($command->week);
        $key = "practice-capture-retry:{$command->sectionId}:{$weekStart->toDateString()}";

        if (! RateLimiter::attempt($key, 1, fn () => true, self::THROTTLE_SECONDS)) {
            throw new CaptureRetryThrottledException(RateLimiter::availableIn($key));
        }

        $practice = $this->audit->record(
            actorId: $actor->id,
            action: 'practice.capture_requested',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->practices->findOrCreate($command->sectionId, $weekStart),
        );

        $now = CarbonImmutable::now('UTC');
        $weekEnd = $practice->week_start->addWeek();

        return new CaptureSectionPractice(
            $practice->id,
            $platform,
            $practice->week_start->toDateTimeImmutable(),
            ($now->lessThan($weekEnd) ? $now : $weekEnd)->toDateTimeImmutable(),
            $actor->id,
        );
    }
}
