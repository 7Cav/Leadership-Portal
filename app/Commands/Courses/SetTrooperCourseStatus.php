<?php

namespace App\Commands\Courses;

use App\Models\CourseStatus;
use App\Models\TrooperCourse;
use App\Models\User;
use App\Repositories\TrooperCourseRepository;
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
 * Setting the status a row already has is recorded like any other change. Two first-time sets for the same
 * trooper and course at once cannot both create the row: the second meets the UNIQUE constraint and throws.
 */
class SetTrooperCourseStatus
{
    public const MAX_REASON_LENGTH = 1000;

    public function __construct(
        private readonly TrooperCourseRepository $trooperCourses,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws CourseWriteForbiddenException when the actor is not an Admin.
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

        if ($reason !== null && mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new InvalidArgumentException('A reason may be at most '.self::MAX_REASON_LENGTH.' characters.');
        }

        $awardedAt = $status->holds() && $awardedAt !== null ? CarbonImmutable::instance($awardedAt)->startOfDay() : null;

        if ($awardedAt !== null && $awardedAt->toDateString() > CarbonImmutable::now('UTC')->toDateString()) {
            throw new InvalidArgumentException('A course cannot be awarded on a date that has not happened yet.');
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'trooper_course.status_set',
            reason: $reason,
            reasonRequired: false,
            mutation: function () use ($trooperId, $courseId, $status, $awardedAt, $actor) {
                $existing = $this->trooperCourses->find($trooperId, $courseId);

                return $existing === null
                    ? $this->trooperCourses->create($trooperId, $courseId, $status, $awardedAt, $actor->id)
                    : $this->trooperCourses->update($existing, $status, $awardedAt, $actor->id);
            },
        );
    }
}
