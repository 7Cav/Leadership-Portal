<?php

namespace App\Kpi;

use App\Models\Trooper;
use App\Models\Unit;
use App\Repositories\CrossSectionSightingRepository;
use App\Repositories\TrooperRepository;
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
 * A guest of `$unit` is a serving trooper placed in a section outside `$unit` who was recorded at a practice of a
 * section under it (by a host leader's hand; game-server sightings recorded before 2026-10-23 count too, and the
 * capture records them again once practices have set times) during the last
 * {@see JsamCriteria::$windowWeeks} Cav weeks, ending with `$weekStart`. Their visits count the unit's host practices,
 * once each whatever the source, so the count the commander reads is the count the badge is decided on: a company sees
 * a guest's visits to all its sections, one of its sections only those to itself. A trooper who has since moved into
 * the unit is not its guest any more, and a departed trooper is left out.
 *
 * Their own SP % is never re-derived here: it is `KpiService::ownSectionPractice()` over the same window (AD-21), the
 * shared SP without makeups (decided 2026-10-23): a makeup answers the weekly accountability but is not attendance at
 * their own section, and a week their own section did not practise stays out, so visits can never stand in for their
 * own. LOA weeks and held practices count exactly as on every other screen. One query for the guests, one for their
 * rows, and the KPI service's two, however many sections they come from.
 *
 * Read-only. The caller passes only a unit the ScopeResolver permits (AD-8); a guest from outside that scope is shown by
 * name, rank, own section and these numbers only, never with a link to a milpac the viewer may not open.
 */
final class PracticeGuests
{
    public function __construct(
        private readonly CrossSectionSightingRepository $sightings,
        private readonly TrooperRepository $troopers,
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
        $ownSp = $this->kpis->ownSectionPractice($troopers->keys(), $weekStart, $criteria->windowWeeks);

        $guests = $troopers->map(function (Trooper $trooper) use ($visits, $ownSp, $criteria) {
            $seen = $visits->get($trooper->id);

            return new PracticeGuest(
                trooper: $trooper,
                visits: $seen->visits,
                lastWeek: $seen->lastWeek,
                ownSp: $ownSp->get($trooper->id, new Ratio(0, 0)),
                candidate: $criteria->isCandidate($seen->visits, $ownSp->get($trooper->id, new Ratio(0, 0))),
            );
        })->values()->all();

        usort($guests, fn (PracticeGuest $a, PracticeGuest $b) => [$b->candidate, $b->visits, $b->lastWeek->getTimestamp(), mb_strtolower((string) $a->trooper->username), $a->trooper->id]
            <=> [$a->candidate, $a->visits, $a->lastWeek->getTimestamp(), mb_strtolower((string) $b->trooper->username), $b->trooper->id]);

        return $guests;
    }
}
