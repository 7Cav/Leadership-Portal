<?php

namespace App\Kpi;

use App\Loa\LoaCoverage;
use App\Models\Trooper;
use App\Models\UnaccountedReason;
use App\Models\Unit;
use App\Repositories\TrooperPlacementRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The reason the portal can suggest for a trooper left unaccounted on a section's weekly report, when its own data
 * says why (2026-10-29): they departed the Cav, they moved out of the section during the week, or they posted leave the
 * LOA sync could not read. Only a suggestion: the report page pre-selects it for the leader to keep or change, and
 * nothing is saved or sent unless the leader does. A trooper the data says nothing about gets none.
 *
 * When more than one applies, the firmest fact wins: departed, then moved out, then leave that needs review.
 */
final class UnaccountedSuggestions
{
    public function __construct(
        private readonly LoaCoverage $loaCoverage,
        private readonly TrooperPlacementRepository $placements,
    ) {}

    /**
     * @param  Collection<int, Trooper>  $troopers  the report's troopers
     * @return array<int, string> trooper id => an {@see UnaccountedReason} value
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function forSection(Unit $section, CarbonInterface $weekStart, Collection $troopers): array
    {
        $ids = $troopers->map(fn (Trooper $trooper) => $trooper->id)->values()->all();

        if ($ids === []) {
            return [];
        }

        $movedOut = array_flip($this->placements->leftUnitBetween($section->id, $ids, WeekOf::requireStart($weekStart), WeekOf::next($weekStart)));
        $covers = $this->loaCoverage->forTroopers($ids, $weekStart);
        $suggested = [];

        foreach ($troopers as $trooper) {
            $reason = match (true) {
                $trooper->status !== null && ! $trooper->status->isServing() => UnaccountedReason::Departed,
                isset($movedOut[$trooper->id]) => UnaccountedReason::MovedOut,
                $covers[$trooper->id]->needsReviewState() => UnaccountedReason::LoaNeedsReview,
                default => null,
            };

            if ($reason !== null) {
                $suggested[$trooper->id] = $reason->value;
            }
        }

        return $suggested;
    }
}
