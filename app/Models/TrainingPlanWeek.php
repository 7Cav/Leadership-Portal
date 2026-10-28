<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The subject a training plan (#380) gives one Cav week, UNIQUE on `(training_plan_id, week_start)`. `week_start` is
 * always a Saturday 00:00 UTC (AD-11). Portal-owned (AD-22): written only by the Repository layer.
 */
#[Fillable(['training_plan_id', 'week_start', 'subject'])]
class TrainingPlanWeek extends Model
{
    protected function casts(): array
    {
        return [
            'week_start' => 'immutable_datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(TrainingPlan::class, 'training_plan_id');
    }
}
