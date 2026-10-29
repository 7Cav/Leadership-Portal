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
 *
 * "Moved out" is read from the placement history: their placement in the section ended during the week and they were
 * not back in it when the week ended (the 2026-10-29 audit). On a report such a trooper is one the week's roster still
 * lists because they moved to a unit with no rollcall (HQ staff, a group); one who left and came back within the week is
 * not suggested it, and neither is one who moved only after the week.
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
                // Out of the section by the week's end and not placed in it now: one who left and came back is not.
                isset($movedOut[$trooper->id]) && $trooper->primary_section_id !== $section->id => UnaccountedReason::MovedOut,
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
