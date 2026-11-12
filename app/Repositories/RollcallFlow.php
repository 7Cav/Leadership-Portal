<?php

namespace App\Repositories;

use App\Models\SectionPractice;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Which roll call flow a section-week follows (#703). `V1` is the three steps the roll call always had; `V2` the explicit
 * weekly cycle: create the roll call with its date and topic, post it, sign and mark, then close it, which sends the
 * weekly report. A week takes `V2` when it starts on or after `portal.rollcall_flow_v2_from` (a Saturday, AD-11), and the
 * flow is kept on its practice row once one exists, so a week never changes flow while it runs: rows from before the
 * cutover keep `V1`. Null for the setting keeps every week on `V1`.
 */
final class RollcallFlow
{
    public const V1 = 1;

    public const V2 = 2;

    /** The flow a week starting at `$weekStart` is created under. */
    public static function forWeek(CarbonInterface $weekStart): int
    {
        $from = config('portal.rollcall_flow_v2_from');

        if ($from === null) {
            return self::V1;
        }

        $cutover = WeekOf::start(CarbonImmutable::parse($from, 'UTC'));

        return WeekOf::start($weekStart)->greaterThanOrEqualTo($cutover) ? self::V2 : self::V1;
    }

    /** The flow of a section-week: its practice row's, or the week's own while it has none yet. */
    public static function of(?SectionPractice $practice, CarbonInterface $weekStart): int
    {
        return $practice === null ? self::forWeek($weekStart) : (int) $practice->flow_version;
    }
}
