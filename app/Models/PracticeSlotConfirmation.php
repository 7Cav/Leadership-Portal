<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A platoon leader's answer for one section and one clock change (16NOV26): the slot (`weekday` 0 Sunday to 6
 * Saturday, `start_minute` after 00:00Z) kept as it was, or `moved` to stay at the same local time. `change_at` is the
 * moment the clocks change, in UTC. Written only by `PracticeSlotConfirmationRepository` for the audited
 * `ConfirmClockChange` Command.
 */
#[Fillable(['section_id', 'change_at', 'weekday', 'start_minute', 'moved', 'confirmed_by'])]
class PracticeSlotConfirmation extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'change_at' => 'immutable_datetime',
            'weekday' => 'integer',
            'start_minute' => 'integer',
            'moved' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
