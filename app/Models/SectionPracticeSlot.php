<?php

namespace App\Models;

use App\Support\Time\PracticeWindow;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A section's weekly practice slot (#392): `weekday` (0 Sunday to 6 Saturday) and `start_minute` (minutes after
 * 00:00Z). A section practice is always one hour, so the window is the start plus 60 minutes, placed in the Cav week of
 * its start ({@see PracticeWindow}). Written only by the Repository layer for the audited
 * `SetPracticeSlot` Command.
 */
#[Fillable(['section_id', 'weekday', 'start_minute'])]
class SectionPracticeSlot extends Model
{
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'start_minute' => 'integer',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'section_id');
    }
}
