<?php

namespace App\Repositories;

use App\Models\SectionPractice;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Which roll call flow a section-week follows (#703). `V1` is the three steps the roll call always had; `V2` the explicit
 * weekly cycle: create the roll call with its date and topic, post it, sign and mark, then close it, which sends the
 * weekly report. A week takes `V2` when it starts on or after the cutover Saturday (AD-11): the one the release
 * recorded (`rollcall_flow_cutovers`, the first Saturday after its migration ran), or `portal.rollcall_flow_v2_from`
 * when that names one; `false` there keeps every week on `V1`. The flow is fixed on the week's practice row when the
 * row is made, and read from there after: rows from before the cutover keep `V1`, and a later change of the cutover
 * moves only weeks with no row yet.
 */
final class RollcallFlow
{
    public const V1 = 1;

    public const V2 = 2;

    /** The flow a week starting at `$weekStart` is created under. */
    public static function forWeek(CarbonInterface $weekStart): int
    {
        $cutover = self::cutover();

        return $cutover !== null && WeekOf::start($weekStart)->greaterThanOrEqualTo($cutover) ? self::V2 : self::V1;
    }

    /** The flow of a section-week: its practice row's, or the week's own while it has none yet. */
    public static function of(?SectionPractice $practice, CarbonInterface $weekStart): int
    {
        return $practice === null ? self::forWeek($weekStart) : (int) $practice->flow_version;
    }

    /** The first week on `V2`, or null when no week is. */
    private static function cutover(): ?CarbonImmutable
    {
        $setting = config('portal.rollcall_flow_v2_from');

        if ($setting === false) {
            return null;
        }

        $from = $setting ?? DB::table('rollcall_flow_cutovers')->orderBy('id')->value('first_week');

        return $from === null ? null : WeekOf::start(CarbonImmutable::parse($from, 'UTC'));
    }
}
