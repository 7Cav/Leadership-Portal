<?php

namespace App\Commands\TrainingPlans;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * The actor may not do this to the platoon's training plan: they do not command the platoon (to write it) or its
 * company (to review it), or the platoon is outside their scope or does not exist. A staff grant reads a plan, it
 * never writes or reviews one. An `AuthorizationException`, so an HTTP caller that lets it propagate answers 403.
 */
class TrainingPlanForbiddenException extends AuthorizationException
{
    public static function write(int $unitId): self
    {
        return new self("You may not write the training plan for unit {$unitId}: you do not command it.");
    }

    public static function review(int $unitId): self
    {
        return new self("You may not review the training plan for unit {$unitId}: you do not command its company.");
    }
}
