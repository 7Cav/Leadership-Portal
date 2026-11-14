<?php

namespace App\Http\Controllers;

use App\Authorization\StaffReportAccess;
use App\Loa\LoaCoverage;
use App\Milpac\StaffReportWeek;
use App\Models\Trooper;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;

/**
 * Whose staff report for the week before is still not sent: what the staff report pages warn about, since they open
 * on the running week. Owed means they filed one for that week (on a platoon, company or battalion HQ when it ended,
 * and still serving) and have not sent it. Nothing before the week floor is owed, and a week their leave covered
 * ({@see LoaCoverage}, as the KPIs read it) is not called missed.
 */
class StaffReportOwed
{
    public function __construct(
        private readonly StaffReportAccess $access,
        private readonly StaffReportWeek $week,
        private readonly LoaCoverage $loas,
    ) {}

    /** `YYYY-MM-DD` of the week before `$weekStart` when the trooper still owes its report, else null. */
    public function previousWeek(Trooper $trooper, CarbonImmutable $weekStart): ?string
    {
        $previous = $weekStart->subWeek();

        if (WeekOf::isBeforeFloor($previous) || $this->access->owes($trooper, $previous) === null
            || $this->week->find($trooper, $previous) !== null || $this->loas->forTrooper($trooper->id, $previous)->isCovered()) {
            return null;
        }

        return $previous->toDateString();
    }

    /**
     * The troopers on the given HQs when the week before `$weekStart` ended who still owe its report, as a set: trooper
     * id => true. A fixed number of queries however many HQs.
     *
     * @param  list<int>  $hqIds
     * @return array<int, true>
     */
    public function onHqs(array $hqIds, CarbonImmutable $weekStart): array
    {
        $previous = $weekStart->subWeek();

        if ($hqIds === [] || WeekOf::isBeforeFloor($previous)) {
            return [];
        }

        $unsent = [];
        foreach ($this->week->staffOfHqs($hqIds, $previous) as $rows) {
            foreach ($rows as $row) {
                if ($row['report'] === null && $row['trooper']->status->isServing()) {
                    $unsent[$row['trooper']->id] = true;
                }
            }
        }

        if ($unsent === []) {
            return [];
        }

        $covers = $this->loas->forTroopers(array_keys($unsent), $previous);

        return array_filter($unsent, fn (bool $owed, int $id) => ! $covers[$id]->isCovered(), ARRAY_FILTER_USE_BOTH);
    }
}
