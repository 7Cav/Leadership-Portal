<?php

namespace App\Models;

use App\Support\Time\PracticeWindow;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A section's weekly practice slot (#392): `weekday` (0 Sunday to 6 Saturday) and `start_minute` (minutes after
 * 00:00Z). A section practice is always one hour, so the window is the start plus 60 minutes, placed in the Cav week of
 * its start ({@see PracticeWindow}). Written only by the Repository layer for the audited
 * `SetPracticeSlot` Command.
 *
 * A slot set while the current week was already held starts with `effective_from` (a Cav week's Saturday); the weeks
 * before it keep `previous_weekday`/`previous_start_minute`, or no slot when those are null. {@see placingFor()}.
 */
#[Fillable(['section_id', 'weekday', 'start_minute', 'effective_from', 'previous_weekday', 'previous_start_minute'])]
class SectionPracticeSlot extends Model
{
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'start_minute' => 'integer',
            'effective_from' => 'immutable_date',
            'previous_weekday' => 'integer',
            'previous_start_minute' => 'integer',
        ];
    }

    /**
     * The slot in effect in the week starting `$weekStart`, as `[weekday, startMinute]`, or null when the section had
     * none that week (a first slot that starts later).
     *
     * @return array{0: int, 1: int}|null
     */
    public function placingFor(CarbonInterface $weekStart): ?array
    {
        if (! $this->startsLater($weekStart)) {
            return [$this->weekday, $this->start_minute];
        }

        return $this->previous_weekday === null || $this->previous_start_minute === null
            ? null
            : [$this->previous_weekday, $this->previous_start_minute];
    }

    /** True when the slot only starts after the week starting `$weekStart`. */
    public function startsLater(CarbonInterface $weekStart): bool
    {
        return $this->effective_from !== null
            && CarbonImmutable::instance($weekStart)->utc()->startOfDay()->lessThan($this->effective_from->utc()->startOfDay());
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'section_id');
    }
}
