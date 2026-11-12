<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A leader's request to edit a report that locked at its deadline: a unit's weekly report (`trooper_id` null), or a
 * staff member's staff report (`trooper_id` the staff member, `unit_id` their HQ that week). A leader above approves
 * or denies it. Approving sets `unlocked_until` (the coming Saturday 2359Z) here and on the report. Written only by
 * the audited report edit request Commands.
 */
#[Fillable(['unit_id', 'trooper_id', 'week_start', 'requested_by', 'real_requested_by', 'reason'])]
class ReportEditRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DENIED = 'denied';

    protected function casts(): array
    {
        return [
            'week_start' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'unlocked_until' => 'immutable_datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function trooper(): BelongsTo
    {
        return $this->belongsTo(Trooper::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** A staff report's request, rather than a unit's weekly report. */
    public function isStaff(): bool
    {
        return $this->trooper_id !== null;
    }
}
