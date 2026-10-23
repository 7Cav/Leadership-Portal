<?php

namespace App\Commands\WeeklyReports;

use App\Authorization\ScopeResolver;
use App\Models\PlatoonTrainingTopic;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReportStatus;
use App\Repositories\PlatoonTrainingTopicRepository;
use App\Repositories\UnitRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekOf;

/**
 * Sets or clears a platoon's training topic for one Cav week through the audited envelope (AD-16;
 * no reason, like a draft save). Each section's report then reads it: locked when enforced,
 * otherwise as a default the section leader may change. Reports already sent keep the subject they
 * froze.
 *
 * Refused before anything is written: a unit outside the actor's scope or one they do not command
 * (a staff grant reads a unit, it does not write to it; an Admin may), a unit that is not a
 * platoon, a week before `WeekOf::floor()`, and a week whose platoon report is already sent.
 */
class SetPlatoonTrainingTopicHandler
{
    public const SET = 'platoon_training_topic.set';

    public const CLEARED = 'platoon_training_topic.cleared';

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly WeeklyReportRepository $reports,
        private readonly PlatoonTrainingTopicRepository $topics,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * The topic as now set, or null once cleared (clearing a week with no topic is a no-op, with no
     * audit entry).
     *
     * @throws PlatoonTrainingTopicForbiddenException when the actor does not command the unit, it
     *                                                is outside their scope, or does not exist.
     * @throws InvalidPlatoonTrainingTopicException when the unit is not a platoon, the week is
     *                                              before `WeekOf::floor()`, or its report for the
     *                                              week is already sent.
     */
    public function handle(User $actor, SetPlatoonTrainingTopic $command): ?PlatoonTrainingTopic
    {
        $unit = $this->scope->resolve($actor)->contains($command->unitId) ? $this->units->find($command->unitId) : null;

        if ($unit === null || ! $this->scope->commandsUnit($actor, $unit)) {
            throw new PlatoonTrainingTopicForbiddenException($command->unitId);
        }

        if ($unit->type !== UnitType::Platoon) {
            throw InvalidPlatoonTrainingTopicException::notAPlatoon();
        }

        $weekStart = WeekOf::start($command->week);

        // The same floor every rollcall and report write keeps: a topic is a write to the week's
        // reports, so it may not reach a week too far back to report.
        if (WeekOf::isBeforeFloor($weekStart)) {
            throw InvalidPlatoonTrainingTopicException::weekBeforeFloor($weekStart);
        }

        if ($this->reports->find($unit->id, $weekStart)?->status === WeeklyReportStatus::Sent) {
            throw InvalidPlatoonTrainingTopicException::reportSent();
        }

        $subject = blank($command->subject) ? null : trim($command->subject);

        if ($subject === null) {
            if ($this->topics->find($unit->id, $weekStart) === null) {
                return null;
            }

            $this->audit->record(
                actorId: $actor->id,
                action: self::CLEARED,
                reason: null,
                reasonRequired: false,
                mutation: fn () => $this->topics->clear($unit->id, $weekStart),
            );

            return null;
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: self::SET,
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->topics->set($unit->id, $weekStart, $subject, $command->enforced, $actor->id),
        );
    }
}
