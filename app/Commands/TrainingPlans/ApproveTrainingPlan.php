<?php

namespace App\Commands\TrainingPlans;

/**
 * Approve a platoon's training plan for `$month` (`YYYY-MM`), as read at `$revision`, so a plan changed after the
 * reviewer opened it is not approved unseen. Handled by {@see ApproveTrainingPlanHandler}.
 */
final readonly class ApproveTrainingPlan
{
    public function __construct(
        public int $unitId,
        public string $month,
        public int $revision,
    ) {}
}
