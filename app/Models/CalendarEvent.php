<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One time an event on the 7Cav forum calendar takes place (#751): a one-off event has one row, a weekly one a row for
 * each week the sync has read it into. A synced cache (AD-4, AD-22): written only by `Sync\SyncCalendarHandler` through
 * `CalendarEventRepository`, read by the roll call. `uid` is the forum's event id; `title` is the event's title as the
 * forum has it (`SQUAD | Squad Saturday @1900Z`). `calendar_id` is the forum calendar it is filed in, when it was read
 * from a calendar a game names, which ties it to that game; otherwise its title does ({@see Game::claimsEvent()}).
 * Times are UTC.
 */
#[Fillable(['uid', 'calendar_id', 'title', 'starts_at', 'ends_at', 'url'])]
class CalendarEvent extends Model
{
    protected function casts(): array
    {
        return [
            'calendar_id' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }
}
