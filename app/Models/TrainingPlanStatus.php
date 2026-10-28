<?php

namespace App\Models;

/**
 * A `training_plans` row's review state (#380). The values mirror the `training_plans.status` DB enum by hand; change
 * both together. Every save puts a plan back to `Pending`, so a plan changed after approval needs approval again.
 */
enum TrainingPlanStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Returned = 'returned';
}
