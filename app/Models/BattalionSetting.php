<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A battalion's own settings (#558). `presence_minimum_minutes` is how long a trooper must be on the practice's server
 * inside its hour to count as attending it; null uses `portal.presence_minimum_minutes`. Portal-owned (AD-22), written
 * only through `BattalionSettingRepository` by the audited `SetPresenceMinimum` Command.
 */
#[Fillable(['unit_id', 'presence_minimum_minutes'])]
class BattalionSetting extends Model
{
    protected function casts(): array
    {
        return [
            'unit_id' => 'integer',
            'presence_minimum_minutes' => 'integer',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
