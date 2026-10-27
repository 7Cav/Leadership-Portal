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
}
