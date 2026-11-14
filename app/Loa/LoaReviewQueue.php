<?php

namespace App\Loa;

use App\Repositories\TrooperRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The leave posts that need review for a set of sections: the one list Home's Needs attention shows and the roster's
 * leave sync note counts, so the two never disagree. One entry per trooper, whatever how many of their posts need
 * review, and only for a trooper on one of the sections' rollcalls in the week it bears on.
 *
 * The weeks are the reporting week, and the running week when that is a later one (a post made since the closing week
 * ended). Each is checked against its own rollcalls ({@see TrooperRepository::onRollcalls()}, the 2026-10-29 audit),
 * so a trooper who has left the sections since is not checked for the running week, and one who joined this week is.
 * Which weeks a post bears on is {@see LoaCoverage}'s rule (AD-21). Read-only.
 */
final class LoaReviewQueue
{
    public function __construct(
        private readonly LoaCoverage $loa,
        private readonly TrooperRepository $troopers,
    ) {}

    /**
     * By trooper id, in trooper id order: the cover that needs review, the section whose rollcall lists them and the
     * week to open it on (the reporting week's when they are on it, else the running week's). The later week's post is
     * the one kept: it is the one a leader can still act on.
     *
     * @param  list<int>  $sectionIds
     * @return array<int, array{cover: LoaCover, section: int, week: string}>
     */
    public function forSections(array $sectionIds, CarbonInterface $weekStart, ?CarbonInterface $now = null): array
    {
        if ($sectionIds === []) {
            return [];
        }

        $week = WeekOf::requireStart($weekStart);
        $running = WeekOf::start(CarbonImmutable::instance($now ?? CarbonImmutable::now()));
        $date = $week->toDateString();
        // trooper id => their section, on each week's rollcalls.
        $rosters = [$date => $this->troopers->onRollcalls($sectionIds, $week)->sections];

        if ($running->greaterThan($week)) {
            $rosters[$running->toDateString()] = $this->troopers->onRollcalls($sectionIds, $running)->sections;
        }

        $ids = array_keys(array_replace(...array_values($rosters)));

        if ($ids === []) {
            return [];
        }

        $review = [];

        foreach ($this->loa->forTroopersOverWeeks($ids, $week, count($rosters)) as $trooperId => $covers) {
            $found = collect(array_reverse($covers, true))
                ->first(fn (LoaCover $cover, string $at) => isset($rosters[$at][$trooperId]) && $cover->needsReviewState());

            if ($found !== null) {
                $at = isset($rosters[$date][$trooperId]) ? $date : array_key_last($rosters);
                $review[$trooperId] = ['cover' => $found, 'section' => $rosters[$at][$trooperId], 'week' => $at];
            }
        }

        ksort($review);

        return $review;
    }
}
