<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A serving trooper an Admin assigned to lead a unit the Cav roster names no lead for (decided 2026-10-25): a
 * `DEVCOM Lead` acting as the Platoon Leader of one or more DEVCOM starter platoons. One per unit; a trooper may lead
 * several. ScopeResolver treats it as a command over the unit, only while the trooper is serving. Written only by the
 * audited acting-lead Commands.
 */
#[Fillable(['unit_id', 'trooper_id', 'assigned_by'])]
class UnitActingLead extends Model
{
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function trooper(): BelongsTo
    {
        return $this->belongsTo(Trooper::class);
    }
}
