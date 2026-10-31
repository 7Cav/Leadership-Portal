<?php

namespace App\Support\Time;

use DateTimeInterface;

/**
 * The label a user reads for a Cav week (#568): its end date, the Friday, in the forum's `dMy` convention, as the
 * Google Form names it ("Week Ending 30Oct26"). Only the label: a week is still stored and keyed by its start
 * ({@see WeekOf::start()}). The front end's twin is `weekLabel()` in `resources/js/lib/forumDate.js`.
 */
final class WeekLabel
{
    /**
     * `30Oct26` for the Cav week containing `$moment`: a `week_start` names the week it opens, any other moment the
     * week it falls in, read at its UTC instant.
     */
    public static function ending(DateTimeInterface $moment): string
    {
        return WeekOf::start($moment)->addDays(6)->format('dMy');
    }
}
