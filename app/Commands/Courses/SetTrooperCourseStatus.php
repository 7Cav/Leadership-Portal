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
 * write. Who may do it is the caller's gate (AD-6), as for every other write.
 *
 * A trooper has one row per course. The first time sets it; after that the same call moves it, and the audit
 * entry's `reason` says why if the caller gives one. No reason is required: this keeps a record, it is not a
 * personnel action.
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
    public function __construct(
        private readonly TrooperCourseRepository $trooperCourses,
        private readonly AuditEnvelope $audit,
    ) {}

    public function handle(
        User $actor,
        int $trooperId,
        int $courseId,
        CourseStatus $status,
        ?CarbonInterface $awardedAt = null,
        ?string $reason = null,
    ): TrooperCourse {
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
