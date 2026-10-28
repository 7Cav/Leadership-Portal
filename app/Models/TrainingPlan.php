<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A platoon's monthly training plan (#380): one subject per Cav week of `month` (`YYYY-MM`), reviewed by the company.
 * UNIQUE on `(unit_id, month)`. Portal-owned (AD-22): the Repository layer is its only writer, for the audited
 * training plan Commands (AD-16).
 *
 * Nothing is mass-assignable: the status, the revision and the review columns move only together, in the Repository's
 * save, approve and return paths, so a stray `update()` can never mark a plan approved without its reviewer.
 */
class TrainingPlan extends Model
{
    /** Mirrors the columns' DB defaults, so a freshly created model reads them without a refresh. */
    protected $attributes = [
        'status' => 'pending',
        'enforced' => false,
        'revision' => 1,
    ];

    protected function casts(): array
    {
        return [
            'status' => TrainingPlanStatus::class,
            'enforced' => 'boolean',
            'revision' => 'integer',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** The plan's weeks that have a subject, earliest first. */
    public function weeks(): HasMany
    {
        return $this->hasMany(TrainingPlanWeek::class)->orderBy('week_start');
    }

    public function writer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'written_by');
    }

    /** The Admin who wrote it while acting as `written_by` (#379), or null. */
    public function writerAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'written_by_admin');
    }

    /** The unit whose billet `written_by`, an Admin, held when writing it (#379), or null. */
    public function writtenAsUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'written_as_unit');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** The Admin who reviewed it while acting as `reviewed_by` (#379), or null. */
    public function reviewerAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_admin');
    }

    /** The unit whose billet `reviewed_by`, an Admin, held when reviewing it (#379), or null. */
    public function reviewedAsUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'reviewed_as_unit');
    }
}
