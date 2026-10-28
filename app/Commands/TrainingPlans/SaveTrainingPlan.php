<?php

namespace App\Commands\TrainingPlans;

/**
 * Write a platoon's training plan for `$month` (`YYYY-MM`): `$subjects` maps the `YYYY-MM-DD` Saturday of each Cav week
 * of the month to its training subject (blank or missing for a week with none). `$enforced` decides how an approved
 * plan reaches the section reports: locked, or as a default each section may change. Every save goes to the company for
 * approval. Handled by {@see SaveTrainingPlanHandler}.
 */
final readonly class SaveTrainingPlan
{
    /**
     * @param  array<string, ?string>  $subjects
     */
    public function __construct(
        public int $unitId,
        public string $month,
        public array $subjects,
        public bool $enforced = false,
    ) {}
}
