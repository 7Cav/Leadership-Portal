<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A unit's own accountability options (`AccountabilityRules`): which kinds of attendance count for its HQ staff report
 * or, on a section, as a makeup. Portal-owned (AD-22), written only through `UnitAccountabilitySettingRepository` by
 * the audited `SetAccountabilityOptions` Command.
 */
#[Fillable(['unit_id', 'options'])]
class UnitAccountabilitySetting extends Model
{
    protected function casts(): array
    {
        return [
            'unit_id' => 'integer',
            'options' => 'array',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
