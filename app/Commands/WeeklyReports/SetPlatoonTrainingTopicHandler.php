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
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;

/**
 * Sets or clears a platoon's training topic for one Cav week through the audited envelope (AD-16;
 * no reason, like a draft save). Each section's report then reads it: locked when enforced,
 * otherwise as a default the section leader may change. Reports already sent keep the subject they
 * froze.
 *
 * Refused before anything is written: a unit outside the actor's scope or one they do not command
 * (a staff grant reads a unit, it does not write to it; an Admin may), a unit that is not a
 * platoon, a week before `WeekOf::floor()`, and a week whose platoon report is already sent. The
 * sent check runs once more under the report row's shared lock inside the write's transaction, so
 * a send landing mid-write cannot slip past it; and a clear that a racing clear beat to it is the
 * same clean no-op as clearing a week with no topic. A week whose topic an approved training plan
 * wrote (#380) is refused too: it changes through the plan, which then needs approval again.
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
        private readonly ActingFor $acting,
    ) {}

    /**
     * The topic as now set, or null once cleared (clearing a week with no topic is a no-op, with no
     * audit entry).
     *
     * @throws PlatoonTrainingTopicForbiddenException when the actor does not command the unit, it
     *                                                is outside their scope, or does not exist.
     * @throws InvalidPlatoonTrainingTopicException when the unit is not a platoon, the week is
     *                                              before `WeekOf::floor()`, its report for the
     *                                              week is already sent, or its topic came from an
     *                                              approved training plan.
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

        // A cheap early refusal (a demo report counts as sent here too); checked again under a lock
        // inside each write below.
        if ($this->reports->find($unit->id, $weekStart)?->status === WeeklyReportStatus::Sent) {
            throw InvalidPlatoonTrainingTopicException::reportSent();
        }

        $current = $this->topics->find($unit->id, $weekStart);
        if ($current?->training_plan_id !== null) {
            throw InvalidPlatoonTrainingTopicException::fromPlan();
        }

        $subject = blank($command->subject) ? null : trim($command->subject);

        if ($subject === null) {
            if ($current === null) {
                return null;
            }

            try {
                $this->audit->record(
                    actorId: $actor->id,
                    action: self::CLEARED,
                    reason: null,
                    reasonRequired: false,
                    mutation: function () use ($unit, $weekStart) {
                        $this->refuseSentUnderLock($unit->id, $weekStart);

                        // A racing clear that committed between the check above and this write
                        // leaves nothing to remove: the same no-op as above, not an audited clear
                        // of nothing.
                        return $this->topics->clear($unit->id, $weekStart) ?? throw new TrainingTopicUnchangedException;
                    },
                );
            } catch (TrainingTopicUnchangedException) {
                // A racing clear got there first: the topic is gone either way.
            }

            return null;
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: self::SET,
            reason: null,
            reasonRequired: false,
            mutation: function () use ($unit, $weekStart, $subject, $command, $actor) {
                $this->refuseSentUnderLock($unit->id, $weekStart);

                return $this->topics->set($unit->id, $weekStart, $subject, $command->enforced, $actor->id, $this->acting->adminActingAs($actor->id), $this->acting->billetOf($actor->id));
            },
        );
    }

    /**
     * The sent check again, first inside the write's transaction: `isSentFor()` holds a shared lock
     * on the platoon's report row until the transaction ends, so a send of the platoon's report
     * either waits for the topic write to commit or is seen by it, never a topic changed under a
     * week that was frozen in between (AD-12).
     *
     * @throws InvalidPlatoonTrainingTopicException when the platoon's report for the week is sent.
     */
    private function refuseSentUnderLock(int $unitId, CarbonImmutable $weekStart): void
    {
        if ($this->reports->isSentFor($unitId, $weekStart)) {
            throw InvalidPlatoonTrainingTopicException::reportSent();
        }
    }
}
