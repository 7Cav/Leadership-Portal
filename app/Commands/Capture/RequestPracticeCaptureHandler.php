<?php

namespace App\Commands\Capture;

use App\Authorization\ScopeResolver;
use App\Commands\Attendance\InvalidAttendanceMarkException;
use App\Commands\Attendance\RollcallWeekLockedException;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Validates a leader's "Try again" (Story 7.9) and answers the capture to queue: the section's
 * practice for the week, read for the part of that week that has happened so far, as the actor.
 * The controller queues it; the per-practice lock on the job (7.5) still keeps runs from
 * overlapping. Refuses a section outside the actor's scope (AD-8), a week whose section report has
 * been sent (AD-12, the week is locked), a game with no source set up (nothing would be attempted),
 * a practice the screen offers no "Try again" for, and a second request for the same practice within
 * a minute. The request is an audited action (AD-16).
 *
 * "Try again" is offered only when the practice's telemetry state says `canRetry`: its latest capture
 * failed, nothing was captured, and the section's game is known ({@see PracticeTelemetry}). The same
 * state is checked here, so a direct request cannot re-run a captured practice or start one for a
 * week that was never attempted.
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
        private readonly WeeklyReportRepository $reports,
        private readonly PracticeTelemetry $telemetry,
        private readonly CaptureSources $sources,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws CaptureRetryForbiddenException when the section is outside the actor's scope.
     * @throws InvalidAttendanceMarkException when the unit is not a section.
     * @throws RollcallWeekLockedException when the section's report for the week has been sent.
     * @throws CaptureRetryRefusedException when no source can be attempted for the section's game,
     *                                      or the practice has no failed capture to try again.
     * @throws CaptureRetryThrottledException when the practice was requested a moment ago.
     */
    public function handle(User $actor, RequestPracticeCapture $command): CaptureSectionPractice
    {
        if (! $this->scope->writeScope($actor)->contains($command->sectionId)) {
            throw new CaptureRetryForbiddenException($command->sectionId);
        }

        if ($this->units->find($command->sectionId)?->type !== UnitType::Section) {
            throw InvalidAttendanceMarkException::notASection($command->sectionId);
        }

        $weekStart = WeekOf::start($command->week);

        // A sent week is frozen (AD-12): a capture could not write to it anyway.
        if ($this->reports->isSentFor($command->sectionId, $weekStart)) {
            throw RollcallWeekLockedException::for($command->sectionId, $weekStart);
        }

        $game = $this->telemetry->gameOf($command->sectionId)
            ?? throw CaptureRetryRefusedException::noGameLinked();

        if (! $this->sources->isConfigured($game)) {
            throw CaptureRetryRefusedException::notConfigured($game);
        }

        $practice = $this->practices->find($command->sectionId, $weekStart);

        if ($this->telemetry->stateFor($command->sectionId, $practice)?->canRetry !== true) {
            throw CaptureRetryRefusedException::nothingToRetry();
        }

        $key = "practice-capture-retry:{$command->sectionId}:{$weekStart->toDateString()}";

        if (! RateLimiter::attempt($key, 1, fn () => true, self::THROTTLE_SECONDS)) {
            throw new CaptureRetryThrottledException(RateLimiter::availableIn($key));
        }

        $practice = $this->audit->record(
            actorId: $actor->id,
            action: 'practice.capture_requested',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $practice,
        );

        $now = CarbonImmutable::now('UTC');
        $weekEnd = $practice->week_start->addWeek();

        return new CaptureSectionPractice(
            $practice->id,
            $game->id,
            $practice->week_start->toDateTimeImmutable(),
            ($now->lessThan($weekEnd) ? $now : $weekEnd)->toDateTimeImmutable(),
            $actor->id,
        );
    }
}
