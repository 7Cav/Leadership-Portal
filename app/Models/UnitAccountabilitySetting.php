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
    /** Every option key with its label, in the order the tick list shows them (`AccountabilityRules::OPTIONS`). */
    public const OPTION_LABELS = [
        'sp_own_unit' => 'Section practice in own unit',
        'sp_battalion' => 'Section practice elsewhere in the battalion',
        'ftx' => 'FTX',
        'operation' => 'Operation',
        'class' => 'S7 class',
        'event' => 'Other official event',
        'server_hour' => 'Public or training server hour with a leader present',
        'sp_other_game' => 'Section practice in another game',
    ];

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
