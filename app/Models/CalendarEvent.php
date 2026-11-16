<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One time an event on the 7Cav forum calendar takes place (#751): a one-off event has one row, a weekly one a row for
 * each week the sync has read it into. A synced cache (AD-4, AD-22): written only by `Sync\SyncCalendarHandler` through
 * `CalendarEventRepository`, read by the roll call. `uid` is the forum's event id; `title` is the event's title as the
 * forum has it (`SQUAD | Squad Saturday @1900Z`), which is also what ties it to a game ({@see Game::marksEvent()}).
 * Times are UTC.
 */
#[Fillable(['uid', 'title', 'starts_at', 'ends_at', 'url'])]
class CalendarEvent extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }
}
