<?php

namespace App\Support\Time;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The label a user reads for a Cav week: both its dates, Saturday to Friday, in the portal's date format (`dMy` with the month in capitals)
 * ("24OCT26 to 30OCT26"). The Google Form names a week by its end date alone, which {@see self::ending()} keeps for the
 * Sheet export. Only the label: a week is still stored and keyed by its start ({@see WeekOf::start()}). The front
 * end's twin is `weekLabel()` in `resources/js/lib/forumDate.js`.
 */
final class WeekLabel
{
    /**
     * `24OCT26 to 30OCT26` for the Cav week containing `$moment`: a `week_start` names the week it opens, any other
     * moment the week it falls in, read at its UTC instant.
     */
    public static function range(DateTimeInterface $moment): string
    {
        $start = WeekOf::start($moment);

        return self::date($start).' to '.self::date($start->addDays(6));
    }

    /**
     * `Week Ending 30OCT26 (24OCT26 to 30OCT26)`: the long form for titles and headers. It keeps the Google Form's
     * "Week Ending" name and adds the range. The front end's twin is `weekTitle()`.
     */
    public static function title(DateTimeInterface $moment): string
    {
        return 'Week Ending '.self::ending($moment).' ('.self::range($moment).')';
    }

    /**
     * `week ending 30OCT26 (24OCT26 to 30OCT26)`: the long form mid-sentence. A list of weeks or a tight label keeps
     * {@see self::range()}. The front end's twin is `weekPhrase()`.
     */
    public static function phrase(DateTimeInterface $moment): string
    {
        return 'week ending '.self::ending($moment).' ('.self::range($moment).')';
    }

    /** `30OCT26`, the week's end date alone, the way the Google Form and its Sheet name a week. */
    public static function ending(DateTimeInterface $moment): string
    {
        return self::date(WeekOf::start($moment)->addDays(6));
    }

    /** `24OCT26`: a day in the portal's date format, the month in capitals, read at its UTC instant. */
    public static function date(DateTimeInterface $moment): string
    {
        return strtoupper(CarbonImmutable::instance($moment)->utc()->format('dMy'));
    }
}
