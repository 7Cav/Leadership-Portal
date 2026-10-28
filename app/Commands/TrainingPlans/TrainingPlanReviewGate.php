<?php

namespace App\Commands\TrainingPlans;

use App\Authorization\ScopeResolver;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanStatus;
use App\Models\Unit;
use App\Models\User;
use App\Repositories\TrainingPlanRepository;
use App\Repositories\UnitRepository;

/**
 * The checks an approval and a send-back share (#380): who may review the platoon's plan, and that the plan, read
 * under its row lock, is still the pending version the reviewer read.
 */
class TrainingPlanReviewGate
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly TrainingPlanRepository $plans,
        private readonly TrainingPlanRules $rules,
    ) {}

    /**
     * The platoon, when the actor may review its plan.
     *
     * @throws TrainingPlanForbiddenException when it is outside their scope, missing, or they do not command its company.
     */
    public function platoon(User $actor, int $unitId): Unit
    {
        $platoon = $this->scope->resolve($actor)->contains($unitId) ? $this->units->find($unitId) : null;

        if ($platoon === null || ! $this->rules->mayReview($actor, $platoon)) {
            throw TrainingPlanForbiddenException::review();
        }

        return $platoon;
    }

    /**
     * The month's plan under an exclusive lock, held until the review commits, so a save cannot land between this check
     * and the review's write. Call inside the review's transaction.
     *
     * @throws InvalidTrainingPlanException when there is no plan, it is not pending, or its revision is not `$revision`.
     */
    public function lockPending(Unit $platoon, string $month, int $revision): TrainingPlan
    {
        $plan = $this->plans->lockFor($platoon->id, $month) ?? throw InvalidTrainingPlanException::noPlan();

        if ($plan->status !== TrainingPlanStatus::Pending) {
            throw InvalidTrainingPlanException::notPending();
        }

        if ($plan->revision !== $revision) {
            throw InvalidTrainingPlanException::changedSinceRead();
        }

        return $plan;
    }
}
