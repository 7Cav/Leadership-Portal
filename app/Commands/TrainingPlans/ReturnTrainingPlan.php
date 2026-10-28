<?php

namespace App\Commands\TrainingPlans;

/**
 * Send a platoon's training plan for `$month` (`YYYY-MM`), as read at `$revision`, back to the platoon with
 * `$comment` saying what to change. Handled by {@see ReturnTrainingPlanHandler}.
 */
final readonly class ReturnTrainingPlan
{
    public function __construct(
        public int $unitId,
        public string $month,
        public int $revision,
        public string $comment,
    ) {}
}
