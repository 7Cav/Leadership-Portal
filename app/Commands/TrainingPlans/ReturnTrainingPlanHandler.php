<?php

namespace App\Commands\TrainingPlans;

use App\Models\TrainingPlan;
use App\Models\User;
use App\Repositories\TrainingPlanRepository;
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;
use App\Support\Audit\ReasonRequiredException;

/**
 * Sends a platoon's training plan (#380) back to the platoon with a comment saying what to change. Audited (AD-16)
 * with the comment as the entry's reason, which it requires. Nothing reaches the reports: the topics the last approval
 * wrote, if any, stay until a new version is approved.
 *
 * Refused as an approval is ({@see ApproveTrainingPlanHandler}): a reviewer who does not command the company, or a plan
 * that is missing, not pending, or changed since the reviewer read it.
 */
class ReturnTrainingPlanHandler
{
    public const RETURNED = 'training_plan.returned';

    public function __construct(
        private readonly TrainingPlanReviewGate $gate,
        private readonly TrainingPlanRepository $plans,
        private readonly AuditEnvelope $audit,
        private readonly ActingFor $acting,
    ) {}

    /**
     * @throws TrainingPlanForbiddenException when the actor may not review the platoon's plan.
     * @throws InvalidTrainingPlanException when there is no pending plan at the revision read.
     * @throws ReasonRequiredException when the comment is blank.
     */
    public function handle(User $actor, ReturnTrainingPlan $command): TrainingPlan
    {
        $platoon = $this->gate->platoon($actor, $command->unitId);
        $comment = trim($command->comment);

        return $this->audit->record(
            actorId: $actor->id,
            action: self::RETURNED,
            reason: $comment,
            reasonRequired: true,
            mutation: function () use ($platoon, $command, $comment, $actor) {
                $plan = $this->gate->lockPending($platoon, $command->month, $command->revision);

                return $this->plans->sendBack($plan, $comment, $actor->id, $this->acting->adminActingAs($actor->id), $this->acting->billetOf($actor->id));
            },
        );
    }
}
