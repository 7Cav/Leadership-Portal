<?php

namespace App\Kpi;

use App\Models\Trooper;
use App\Models\Unit;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * "Guests at our practices" (decided by the product owner 2026-10-23): the evidence a commander needs to recommend the
 * Joint Service Achievement Medal, which is "awarded for attending multiple section practices to which they are not
 * assigned while still consistently attending their own" and "recommended by the benefitted combat line unit
 * commander" (7Cav Awards and Decorations, 3.8.4). The portal never awards anything; it lists the guests and marks the
 * candidates.
 *
 * A guest of `$unit` is a serving trooper placed in a section outside `$unit` who was recorded (by the game-server
 * capture or by a host leader's hand) at a practice of a section under it during the last
 * {@see JsamCriteria::$windowWeeks} Cav weeks, ending with `$weekStart`. Their visits count the unit's host practices,
 * once each whatever the source, so the count the commander reads is the count the badge is decided on: a company sees
 * a guest's visits to all its sections, one of its sections only those to itself. A trooper who has since moved into
 * the unit is not its guest any more, and a departed trooper is left out.
 *
 * Their own SP % is never re-derived here: it is `KpiService::trooperAttendance()` for their own section over the same
 * window (AD-21), so LOA weeks, held practices and automatic makeups count exactly as on every other screen. One query
 * for the guests, one for their rows, and the KPI service's three per distinct own section.
 *
 * Read-only. The caller passes only a unit the ScopeResolver permits (AD-8); a guest from outside that scope is shown by
 * name, rank, own section and these numbers only, never with a link to a milpac the viewer may not open.
 */
final class PracticeGuests
{
    public function __construct(
        private readonly CrossSectionSightingRepository $sightings,
        private readonly TrooperRepository $troopers,
        private readonly UnitRepository $units,
        private readonly KpiService $kpis,
    ) {}

    public function criteria(): JsamCriteria
    {
        return JsamCriteria::fromConfig();
    }

    /**
     * The unit's guests, candidates first, then by visits (most first), the most recently seen, and username.
     *
     * @return list<PracticeGuest>
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function forUnit(Unit $unit, CarbonInterface $weekStart): array
    {
        $criteria = $this->criteria();
        $weekStart = WeekOf::requireStart($weekStart);
        $visits = $this->sightings->visitsWithin($unit, $weekStart->subWeeks($criteria->windowWeeks - 1), $weekStart);

        if ($visits->isEmpty()) {
            return [];
        }

        $troopers = $this->troopers->byIds($visits->keys()->all());
        $ownSp = [];

        foreach ($troopers->groupBy('primary_section_id') as $sectionId => $group) {
            $section = $this->units->find((int) $sectionId);
            $rows = $section === null ? collect() : $this->kpis->trooperAttendance($section, $weekStart, $criteria->windowWeeks);

            foreach ($group as $trooper) {
                $ownSp[$trooper->id] = $rows->get($trooper->id)?->practice ?? new Ratio(0, 0);
            }
        }

        $guests = $troopers->map(function (Trooper $trooper) use ($visits, $ownSp, $criteria) {
            $seen = $visits->get($trooper->id);

            return new PracticeGuest(
                trooper: $trooper,
                visits: $seen->visits,
                lastWeek: $seen->lastWeek,
                ownSp: $ownSp[$trooper->id],
                candidate: $criteria->isCandidate($seen->visits, $ownSp[$trooper->id]),
            );
        })->values()->all();

        usort($guests, fn (PracticeGuest $a, PracticeGuest $b) => [$b->candidate, $b->visits, $b->lastWeek->getTimestamp(), mb_strtolower((string) $a->trooper->username), $a->trooper->id]
            <=> [$a->candidate, $a->visits, $a->lastWeek->getTimestamp(), mb_strtolower((string) $b->trooper->username), $b->trooper->id]);

        return $guests;
    }
}
