<?php

namespace App\Commands\TrainingPlans;

use App\Commands\WeeklyReports\SetPlatoonTrainingTopicHandler;
use App\Commands\WeeklyReports\TrainingTopicUnchangedException;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Models\WeeklyReportStatus;
use App\Repositories\AuditedBatch;
use App\Repositories\PlatoonTrainingTopicRepository;
use App\Repositories\TrainingPlanRepository;
use App\Repositories\WeeklyReportRepository;
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekOf;
use Carbon\CarbonInterface;

/**
 * Approves a platoon's training plan (#380) and puts it to work: each week's subject becomes the platoon's training
 * topic for that week (the one `SetPlatoonTrainingTopic` sets by hand), enforced or as a default as the plan says, so
 * every section's report starts with it. One audited entry (AD-16) about the plan, with every topic it wrote.
 *
 * A week that is locked keeps what it has: one before the week floor, and one whose platoon report is sent (AD-12).
 * A section report already sent froze its own subject and never reads the topic again. A week the plan leaves blank
 * keeps whatever topic it had, unless an earlier approval of this same plan wrote it: then it is cleared (its own
 * audited clear), so the reports follow the plan as approved. Each topic records the plan's writer as its setter (the subject is theirs) and the plan
 * as its source, so it changes only through the plan from then on.
 *
 * Refused: a reviewer who does not command the platoon's company ({@see TrainingPlanRules::mayReview()}), and, under
 * the plan's row lock, a plan that is missing, not pending, or changed since the reviewer read it.
 */
class ApproveTrainingPlanHandler
{
    public const APPROVED = 'training_plan.approved';

    public function __construct(
        private readonly TrainingPlanReviewGate $gate,
        private readonly TrainingPlanRepository $plans,
        private readonly WeeklyReportRepository $reports,
        private readonly PlatoonTrainingTopicRepository $topics,
        private readonly AuditEnvelope $audit,
        private readonly ActingFor $acting,
    ) {}

    /**
     * @throws TrainingPlanForbiddenException when the actor may not review the platoon's plan.
     * @throws InvalidTrainingPlanException when there is no pending plan at the revision read.
     */
    public function handle(User $actor, ApproveTrainingPlan $command): TrainingPlan
    {
        $platoon = $this->gate->platoon($actor, $command->unitId);

        return $this->audit->recordBatch(
            actorId: $actor->id,
            action: self::APPROVED,
            reason: null,
            reasonRequired: false,
            mutation: function () use ($platoon, $command, $actor) {
                $plan = $this->gate->lockPending($platoon, $command->month, $command->revision);

                $written = [];
                foreach ($plan->weeks as $week) {
                    if ($this->locked($platoon->id, $week->week_start)) {
                        continue;
                    }

                    $written[] = $this->topics->set($platoon->id, $week->week_start, $week->subject, $plan->enforced, $plan->written_by, $plan->written_by_admin, $plan->written_as_unit, $plan->id);
                }

                // A revision that left a week blank: the topic an earlier approval of this plan wrote there goes, unless
                // the week is locked. A topic set by hand, or by another plan, is never touched.
                $planned = $plan->weeks->map(fn ($week) => $week->week_start->toDateString())->all();
                foreach ($this->topics->fromPlan($plan->id) as $topic) {
                    if (in_array($topic->week_start->toDateString(), $planned, true) || $this->locked($platoon->id, $topic->week_start)) {
                        continue;
                    }

                    // Its own audited clear, as a hand clear is: the batch below re-reads every member as stored, and a
                    // cleared topic is gone. Both commit or roll back together, inside the approval's transaction.
                    try {
                        $this->audit->record(
                            actorId: $actor->id,
                            action: SetPlatoonTrainingTopicHandler::CLEARED,
                            reason: null,
                            reasonRequired: false,
                            mutation: fn () => $this->topics->clear($platoon->id, $topic->week_start) ?? throw new TrainingTopicUnchangedException,
                        );
                    } catch (TrainingTopicUnchangedException) {
                        // Already gone: nothing to clear.
                    }
                }

                $plan = $this->plans->approve($plan, $actor->id, $this->acting->adminActingAs($actor->id), $this->acting->billetOf($actor->id));

                return new AuditedBatch($plan, ...$written);
            },
        )->target;
    }

    /**
     * Whether the week's subject can no longer change: before the floor, or the platoon's report sent. `isSentFor()`
     * holds a shared lock on the report row until the approval commits, so a send landing mid-approval either waits or
     * is seen, as for a topic set by hand; a demo report counts as sent here too, as it does there.
     */
    private function locked(int $platoonId, CarbonInterface $weekStart): bool
    {
        return WeekOf::isBeforeFloor($weekStart)
            || $this->reports->isSentFor($platoonId, $weekStart)
            || $this->reports->find($platoonId, $weekStart)?->status === WeeklyReportStatus::Sent;
    }
}
