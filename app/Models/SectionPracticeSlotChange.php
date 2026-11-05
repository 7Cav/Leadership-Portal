<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One change to a section's weekly practice slot (#630): from `effective_from` (a Cav week's Saturday, null for every
 * week before the first change) the section has `weekday`/`start_minute`, or no slot when they are null. Written only
 * by the `SectionPracticeSlotRepository`, for the audited `SetPracticeSlot` Command.
 */
#[Fillable(['section_id', 'effective_from', 'weekday', 'start_minute'])]
class SectionPracticeSlotChange extends Model
{
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'weekday' => 'integer',
            'start_minute' => 'integer',
        ];
    }
}
