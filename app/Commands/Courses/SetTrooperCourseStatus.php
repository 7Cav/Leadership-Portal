<?php

namespace App\Commands\Courses;

use App\Models\CourseStatus;
use App\Models\TrooperCourse;
use App\Models\User;
use App\Repositories\CourseRepository;
use App\Repositories\TrooperCourseRepository;
use App\Repositories\TrooperRepository;
use App\Support\Audit\AuditEnvelope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Set where a trooper stands on a course (Story 6.2). The one writer of `trooper_courses`, so every change is
 * audited (AD-16), with the actor, the action and the row's resulting state in the same transaction as the
 * write. Admin only, checked here against the real flag (AD-6) before anything is written, so a caller that
 * forgot its own gate still cannot reach the table.
 *
 * A trooper has one row per course. The first time sets it; after that the same call moves it, and the audit
 * entry's `reason` says why if the caller gives one. No reason is required: this keeps a record, it is not a
 * personnel action. A reason longer than {@see self::MAX_REASON_LENGTH} characters is refused, the same bound
 * the HTTP-facing reason fields use, so a caller with no request validation of its own cannot fill the audit
 * log with an unbounded string.
 *
 * The award date belongs to `qualified` alone. Any other status clears it, so a trooper moved back to
 * `in_progress` never keeps the date they once qualified, and one qualified without a date has none rather
 * than a guess. A date that has not happened yet is refused, since a trooper cannot have qualified on it.
 *
 * Setting the status a row already has is recorded like any other change. The write is one atomic upsert on
 * the trooper and course, so two first-time sets at once (two admins, a double click) both succeed and the
 * last one wins, rather than the loser hitting the UNIQUE constraint. A trooper or course id that does not
 * exist is refused up front with a {@see CourseTargetNotFoundException}, not left to a foreign-key error.
 */
class SetTrooperCourseStatus
{
    public const MAX_REASON_LENGTH = 1000;

    public function __construct(
        private readonly TrooperCourseRepository $trooperCourses,
        private readonly TrooperRepository $troopers,
        private readonly CourseRepository $courses,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws CourseWriteForbiddenException when the actor is not an Admin.
     * @throws CourseTargetNotFoundException when the trooper or the course does not exist.
     * @throws InvalidArgumentException when the award date is in the future, or the reason is too long.
     */
    public function handle(
        User $actor,
        int $trooperId,
        int $courseId,
        CourseStatus $status,
        ?CarbonInterface $awardedAt = null,
        ?string $reason = null,
    ): TrooperCourse {
        if (! $actor->is_admin) {
            throw CourseWriteForbiddenException::setTrooperCourseStatus();
        }

        if ($this->troopers->find($trooperId) === null) {
            throw CourseTargetNotFoundException::trooper($trooperId);
        }

        if ($this->courses->find($courseId) === null) {
            throw CourseTargetNotFoundException::course($courseId);
        }

        // A blank reason is no reason: store null, never an empty string that reads as one.
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);

        if ($reason !== null && mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new InvalidArgumentException('A reason may be at most '.self::MAX_REASON_LENGTH.' characters.');
        }

        $awardedAt = $status->holds() && $awardedAt !== null ? CarbonImmutable::instance($awardedAt)->startOfDay() : null;

        // Deliberately the UTC day (AD-11): admins in US time zones are never blocked by it. Do not switch to local time.
        if ($awardedAt !== null && $awardedAt->toDateString() > CarbonImmutable::now('UTC')->toDateString()) {
            throw new InvalidArgumentException('A course cannot be awarded on a date that has not happened yet.');
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'trooper_course.status_set',
            reason: $reason,
            reasonRequired: false,
            mutation: fn () => $this->trooperCourses->set($trooperId, $courseId, $status, $awardedAt, $actor->id),
        );
    }
}
