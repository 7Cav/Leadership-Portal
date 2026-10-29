<?php

namespace App\Kpi;

/**
 * What makes a guest a Joint Service Achievement Medal candidate (agreed with
 * S1): at least `$minVisits` practices of the viewed unit's sections attended while not assigned to them ("multiple"),
 * and their own SP % at least `$minOwnSpPercent` ("consistently attending their own"), both over the last
 * `$windowWeeks` Cav weeks, the current one included. Read from `config('portal.jsam.*')` so S1 can change them.
 */
final readonly class JsamCriteria
{
    public function __construct(
        public int $minVisits,
        public int $minOwnSpPercent,
        public int $windowWeeks,
    ) {}

    /** The configured thresholds, clamped so a mistyped value cannot make everyone a candidate or break the window. */
    public static function fromConfig(): self
    {
        return new self(
            minVisits: max(1, (int) config('portal.jsam.min_visits')),
            minOwnSpPercent: min(100, max(0, (int) config('portal.jsam.min_own_sp_percent'))),
            windowWeeks: max(1, (int) config('portal.jsam.window_weeks')),
        );
    }

    /**
     * Both parts met. Own SP is compared on its parts (`numerator × 100 ≥ percent × denominator`), so 3 of 4 is exactly
     * 75% and no rounding moves a guest across the line; an SP with nothing eligible (every week on LOA, or no practice
     * held or attended) says nothing about attending their own, so it never qualifies.
     */
    public function isCandidate(int $visits, Ratio $ownSp): bool
    {
        return $visits >= $this->minVisits
            && $ownSp->denominator > 0
            && $ownSp->numerator * 100 >= $this->minOwnSpPercent * $ownSp->denominator;
    }

    /**
     * @return array{minVisits: int, minOwnSpPercent: int, windowWeeks: int}
     */
    public function toArray(): array
    {
        return ['minVisits' => $this->minVisits, 'minOwnSpPercent' => $this->minOwnSpPercent, 'windowWeeks' => $this->windowWeeks];
    }
}
