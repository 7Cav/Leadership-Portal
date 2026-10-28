<?php

namespace App\Repositories;

use App\Models\TrainingPlan;
use App\Models\Unit;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;

/**
 * Sole DB access for `training_plans` and `training_plan_weeks` (AD-2, AD-22): a platoon's monthly training plan
 * (#380), one subject per Cav week, and its review. One plan per `(unit_id, month)`, `month` a `YYYY-MM` string.
 *
 * Every plan handed back has its `weeks` (earliest first), its writer and its reviewer loaded, with the Admin and the
 * billet behind each (#379).
 */
interface TrainingPlanRepository
{
    /** The platoon's plan for the month, or null when there is none. */
    public function find(int $unitId, string $month): ?TrainingPlan;

    /**
     * The month's plans of every unit in `$unitIds` that has one, keyed by `unit_id`, in one query (a company reads
     * all its platoons' plans at once). The weeks are loaded; the writer and reviewer are not.
     *
     * @param  list<int>  $unitIds
     * @return Collection<int, TrainingPlan>
     */
    public function forUnits(array $unitIds, string $month): Collection;

    /**
     * The platoon's plan for the month, read under an exclusive row lock that holds until the surrounding transaction
     * ends, so a review and a save of one plan never interleave. Null when there is none.
     *
     * @throws LogicException when called outside a transaction, where the lock would end at once.
     */
    public function lockFor(int $unitId, string $month): ?TrainingPlan;

    /**
     * Write the platoon's plan for the month: its subjects (`YYYY-MM-DD` of each week's Saturday => subject, weeks with
     * no subject left out) replace the earlier ones, and the plan goes back to pending with its review cleared and its
     * revision bumped.
     *
     * @param  array<string, string>  $subjects
     *
     * @throws InvalidArgumentException when a key is not a Saturday 00:00 UTC (AD-11).
     */
    public function save(int $unitId, string $month, array $subjects, bool $enforced, int $writtenBy, ?int $writtenByAdmin = null, ?int $writtenAsUnit = null): TrainingPlan;

    /** Mark the plan approved by the reviewer, now. */
    public function approve(TrainingPlan $plan, int $reviewedBy, ?int $reviewedByAdmin = null, ?int $reviewedAsUnit = null): TrainingPlan;

    /** Mark the plan returned to its writer by the reviewer, now, with their comment. */
    public function sendBack(TrainingPlan $plan, string $comment, int $reviewedBy, ?int $reviewedByAdmin = null, ?int $reviewedAsUnit = null): TrainingPlan;

    /**
     * Whether `$unit` has a training plan for any month. `training_plans.unit_id` restricts deletes, so `DeleteUnit`
     * checks this first to refuse with a reason instead of a raw constraint error.
     */
    public function hasPlansFor(Unit $unit): bool;
}
