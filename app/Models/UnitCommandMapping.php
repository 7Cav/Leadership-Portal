<?php

namespace App\Models;

use Database\Factories\UnitCommandMappingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Cav leadership billet → the unit it commands (1.16, AD-7): the bridge from a trooper's
 * synced `cav_position_id` to their roster-derived command scope. Not synced: the Admin-triggered
 * org-structure import (2.9) seeds it additively from the Cav position groups and never
 * overwrites a row that already exists. Separate from {@see UnitBilletMapping}, which places a
 * trooper in their section.
 */
#[Fillable(['unit_id', 'cav_position_id'])]
class UnitCommandMapping extends Model
{
    /** @use HasFactory<UnitCommandMappingFactory> */
    use HasFactory;

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
