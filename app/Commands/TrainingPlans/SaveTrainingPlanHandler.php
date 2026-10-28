<?php

namespace App\Commands\TrainingPlans;

use App\Authorization\ScopeResolver;
use App\Models\TrainingPlan;
use App\Models\TrainingPlanStatus;
use App\Models\TrainingPlanWeek;
use App\Models\User;
use App\Repositories\AuditedBatch;
use App\Repositories\TrainingPlanRepository;
use App\Repositories\UnitRepository;
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;
use App\Support\Time\WeekOf;
use InvalidArgumentException;

/**
 * Writes a platoon's monthly training plan (#380) through the audited envelope (AD-16; no reason, like the weekly
 * topic it feeds), as one entry about the plan with every week it holds. A save always sends the plan to the company
 * for review again, so a plan changed after approval needs approval again; until then the subjects the last approval
 * wrote stay on the reports.
 *
 * Refused before anything is written: a platoon outside the actor's scope or one they do not command (a staff grant
 * reads a plan, it does not write one; an Admin may), a unit that is not a platoon, a month outside
 * {@see TrainingPlanRules::inRange()}, a date that does not open a week of the month, a plan with no subject at all,
 * and a changed subject for a locked week (sent, or before the floor). Saving a plan exactly as it stands is a no-op
 * with no audit entry, except for a plan sent back, where it puts the plan up for review again.
 */
class SaveTrainingPlanHandler
{
    public const SAVED = 'training_plan.saved';

    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly TrainingPlanRepository $plans,
        private readonly TrainingPlanRules $rules,
        private readonly AuditEnvelope $audit,
        private readonly ActingFor $acting,
    ) {}

    /**
     * @throws TrainingPlanForbiddenException when the actor does not command the platoon, it is outside their scope, or
     *                                        does not exist.
     * @throws InvalidTrainingPlanException when the plan cannot be saved as given (see the class docblock).
     */
    public function handle(User $actor, SaveTrainingPlan $command): TrainingPlan
    {
        $platoon = $this->scope->resolve($actor)->contains($command->unitId) ? $this->units->find($command->unitId) : null;

        if ($platoon === null || ! $this->scope->commandsUnit($actor, $platoon)) {
            throw TrainingPlanForbiddenException::write();
        }

        if (! $this->rules->mayWrite($actor, $platoon)) {
            throw InvalidTrainingPlanException::notAPlatoon();
        }

        try {
            $weeks = WeekOf::inMonth($command->month);
        } catch (InvalidArgumentException) {
            throw InvalidTrainingPlanException::monthOutOfRange();
        }

        if (! TrainingPlanRules::inRange($command->month)) {
            throw InvalidTrainingPlanException::monthOutOfRange();
        }

        $dates = array_map(fn ($week) => $week->toDateString(), $weeks);
        foreach (array_keys($command->subjects) as $date) {
            if (! in_array((string) $date, $dates, true)) {
                throw InvalidTrainingPlanException::unknownWeek((string) $date);
            }
        }

        $existing = $this->plans->find($platoon->id, $command->month);
        $stored = $existing?->weeks->mapWithKeys(fn (TrainingPlanWeek $week) => [$week->week_start->toDateString() => $week->subject])->all() ?? [];

        $subjects = [];
        foreach ($dates as $date) {
            $subject = trim((string) ($command->subjects[$date] ?? ''));
            if ($subject !== '') {
                $subjects[$date] = $subject;
            }
        }

        // A locked week keeps the subject it has (AD-12): the plan can no longer change it, as its report cannot.
        foreach ($this->rules->lockedWeeks($platoon, $weeks) as $date => $why) {
            if (($subjects[$date] ?? null) !== ($stored[$date] ?? null)) {
                $week = $weeks[array_search($date, $dates, true)];

                throw $why === 'sent' ? InvalidTrainingPlanException::weekSent($week) : InvalidTrainingPlanException::weekBeforeFloor($week);
            }
        }

        if ($subjects === []) {
            throw InvalidTrainingPlanException::empty();
        }

        if ($existing !== null && $existing->status !== TrainingPlanStatus::Returned
            && $existing->enforced === $command->enforced && $stored === $subjects) {
            return $existing;
        }

        return $this->audit->recordBatch(
            actorId: $actor->id,
            action: self::SAVED,
            reason: null,
            reasonRequired: false,
            mutation: function () use ($platoon, $command, $subjects, $actor) {
                $plan = $this->plans->save($platoon->id, $command->month, $subjects, $command->enforced, $actor->id, $this->acting->adminActingAs($actor->id), $this->acting->billetOf($actor->id));

                return new AuditedBatch($plan, ...$plan->weeks->all());
            },
        )->target;
    }
}
