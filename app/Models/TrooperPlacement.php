<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stretch of a trooper's primary placement (2026-10-27): placed in `unit_id` from `placed_from` (null: since
 * before the portal kept this history) until `placed_until` (null: still placed there). Written only by
 * `TrooperPlacementRepository`, from the roster sync and the Admin's manual placement. The KPIs read a past week
 * against the section the trooper was placed in that week, and the milpac shows the moves.
 */
#[Fillable(['trooper_id', 'unit_id', 'placed_from', 'placed_until', 'source'])]
class TrooperPlacement extends Model
{
    protected function casts(): array
    {
        return [
            'placed_from' => 'immutable_datetime',
            'placed_until' => 'immutable_datetime',
            'source' => PlacementSource::class,
        ];
    }

    public function trooper(): BelongsTo
    {
        return $this->belongsTo(Trooper::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** The Admin who made this placement by hand, if one did and it was recorded. */
    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by');
    }

    /** The Admin whose change ended this placement with no other (a unit deleted), if recorded. */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
