<?php

namespace App\Loa;

use App\Models\Loa;
use App\Models\LoaParseStatus;
use App\Repositories\LoaRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
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
 * for the Cav week in which the thread was posted on the forum (`posted_at`) and the week after, then stops:
 * long enough for a person to fix the post and the next sync to read it cleanly, and short enough that a
 * broken post cannot drop a trooper out of the KPIs for good. `created_at` stands in only for a row that has
 * no `posted_at` yet. A cleanly read leave that covers the week wins over one that needs review.
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

        return array_map(
            fn (array $weeks) => $weeks[$week->toDateString()],
            $this->forTroopersOverWeeks($trooperIds, $week, 1),
        );
    }

    /**
     * Every given trooper's coverage for each of `$weeks` consecutive Cav weeks from `$firstWeekStart`, in one
     * query however many troopers and weeks: `[trooperId => [weekStart 'Y-m-d' => LoaCover]]`. Every trooper and
     * every week has an entry. This is what a window (the KPI service's trailing four weeks, the heatmap's
     * twenty) reads, so it resolves each week by the same rule as {@see forTroopers()}.
     *
     * @param  iterable<int>  $trooperIds
     * @return array<int, array<string, LoaCover>>
     *
     * @throws InvalidArgumentException when `$firstWeekStart` is not a Saturday 00:00 UTC, or `$weeks` is below 1.
     */
    public function forTroopersOverWeeks(iterable $trooperIds, CarbonInterface $firstWeekStart, int $weeks): array
    {
        if ($weeks < 1) {
            throw new InvalidArgumentException("A window needs at least one week; got {$weeks}.");
        }

        $first = WeekOf::requireStart($firstWeekStart);
        $starts = array_map(fn (int $offset) => $first->addDays($offset * 7), range(0, $weeks - 1));

        $ids = collect($trooperIds)->map(fn ($id) => (int) $id)->unique()->values()->all();
        $covers = [];

        foreach ($ids as $id) {
            foreach ($starts as $start) {
                $covers[$id][$start->toDateString()] = LoaCover::none();
            }
        }

        if ($ids === []) {
            return $covers;
        }

        $today = CarbonImmutable::now('UTC');

        foreach ($this->loas->touchingWeeks($ids, $first, $weeks)->groupBy('trooper_id') as $trooperId => $rows) {
            foreach ($starts as $start) {
                $covers[(int) $trooperId][$start->toDateString()] = $this->resolve($rows->filter(fn (Loa $loa) => $this->touches($loa, $start)), $today);
            }
        }

        return $covers;
    }

    /**
     * Whether a row bears on one Cav week: a cleanly read leave whose days include any of the week's seven, or a
     * row with no usable dates (needs review, or no start) posted in this week or the one before, so it shields
     * the week it was posted in and the next, then stops. `created_at` stands in for a row with no `posted_at`.
     */
    private function touches(Loa $loa, CarbonImmutable $weekStart): bool
    {
        $nextWeek = $weekStart->addDays(7);

        if ($loa->parse_status !== LoaParseStatus::Ok || $loa->starts_at === null) {
            $posted = $loa->posted_at ?? $loa->created_at;

            return $posted !== null && ! $posted->lessThan($weekStart->subDays(7)) && $posted->lessThan($nextWeek);
        }

        return $loa->starts_at->toDateString() <= $weekStart->addDays(6)->toDateString()
            && ($loa->ends_at === null || $loa->ends_at->toDateString() >= $weekStart->toDateString());
    }

    /**
     * @param  iterable<int, Loa>  $rows  one trooper's rows that touch the week
     */
    private function resolve(iterable $rows, CarbonImmutable $today): LoaCover
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
            return LoaCover::covered($covering, $today);
        }

        $review = $rows->sortByDesc('id')->first();

        return $review !== null ? LoaCover::needsReview($review) : LoaCover::none();
    }
}
