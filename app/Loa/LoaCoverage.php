<?php

namespace App\Loa;

use App\Models\Loa;
use App\Models\LoaParseStatus;
use App\Repositories\LoaRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The one definition of "is this trooper LOA-covered in this Cav week" (Story 4.4, AD-21). The badge, the
 * heatmap, the at-risk helper and the KPI service's `on_loa` and LOA-excluded denominators all ask here, so
 * they cannot drift apart. Read-only: it reads `loas` through {@see LoaRepository} and never writes.
 *
 * Coverage is judged in whole Cav weeks (Saturday to Friday UTC, AD-11): a leave that touches any day of a
 * week covers all of it. Dates are calendar days, inclusive at both ends. An open-ended leave (`ends_at`
 * null, an ELOA with no return yet) covers every week from its start on.
 *
 * Fail-safe in both directions (AD-4). A leave that needs review is not read as an absence, and it is not
 * ignored either: it resolves to {@see LoaCoverageState::NeedsReview}, which suppresses an at-risk or AWOL
 * attribution without asserting the trooper is away. The parser keeps no dates for such a row, so it applies
 * to every week from the one in which it was first cached, until a person fixes the post and the next sync
 * reads it cleanly. A cleanly read leave that covers the week wins over one that needs review.
 */
final class LoaCoverage
{
    public function __construct(private readonly LoaRepository $loas) {}

    /**
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function forTrooper(int $trooperId, CarbonInterface $weekStart): LoaCover
    {
        return $this->forTroopers([$trooperId], $weekStart)[$trooperId];
    }

    /**
     * Every given trooper's coverage for the week, keyed by trooper id, one query however many troopers.
     * A trooper with no leave still has an entry (`None`), so a caller can always index by id.
     *
     * @param  iterable<int>  $trooperIds
     * @return array<int, LoaCover>
     *
     * @throws InvalidArgumentException when `$weekStart` is not a Saturday 00:00 UTC.
     */
    public function forTroopers(iterable $trooperIds, CarbonInterface $weekStart): array
    {
        $week = WeekOf::requireStart($weekStart);

        $ids = collect($trooperIds)->map(fn ($id) => (int) $id)->unique()->values()->all();
        $covers = array_fill_keys($ids, LoaCover::none());

        if ($ids === []) {
            return $covers;
        }

        foreach ($this->loas->touchingWeek($ids, $week)->groupBy('trooper_id') as $trooperId => $rows) {
            $covers[(int) $trooperId] = $this->resolve($rows);
        }

        return $covers;
    }

    /**
     * @param  iterable<int, Loa>  $rows  one trooper's rows that touch the week
     */
    private function resolve(iterable $rows): LoaCover
    {
        $rows = collect($rows);

        $covering = $rows
            ->filter(fn (Loa $loa) => $loa->parse_status === LoaParseStatus::Ok && $loa->starts_at !== null)
            // An open end outlasts any dated one; among the rest the later end wins, then the newer row.
            ->sortBy([
                fn (Loa $a, Loa $b) => ($b->ends_at === null ? PHP_INT_MAX : $b->ends_at->getTimestamp()) <=> ($a->ends_at === null ? PHP_INT_MAX : $a->ends_at->getTimestamp()),
                fn (Loa $a, Loa $b) => $b->id <=> $a->id,
            ])
            ->first();

        if ($covering !== null) {
            return LoaCover::covered($covering);
        }

        $review = $rows->sortByDesc('id')->first();

        return $review !== null ? LoaCover::needsReview($review) : LoaCover::none();
    }
}
