<?php

namespace App\Kpi;

/**
 * One assigned trooper's share of the KPIs over the same trailing window (AD-21) — what a unit's
 * `UnitKpis` is summed from, and what a roster or Epic 4's at-risk flag reads per trooper rather
 * than re-deriving it. Built only by `KpiService`.
 */
final readonly class TrooperAttendance
{
    /** At-risk at this many explicit misses in the window (AD-21). */
    public const AT_RISK_MISSES = 2;

    public function __construct(
        public int $trooperId,
        /** Signed roll calls ÷ assigned weeks that are not on leave or excused. */
        public Ratio $rollcall,
        /** Attended practices ÷ practices not on leave or excused that were held (a leader SP-marked someone) or that this trooper attended. */
        public Ratio $practice,
        /** Practices explicitly marked `missed` in the window. */
        public int $missed,
        /** LOA-covered in the `week_start` week. */
        public bool $onLoa,
        /** Makeup marks in the `week_start` week (0 or 1). */
        public int $makeups,
        /** The `week_start` dates (Y-m-d) of the practices counted in `missed`, oldest first. */
        public array $missedWeeks = [],
        /**
         * The section the row counts in: the one they are placed in now, or for a week that has ended, the one they
         * ended it in (the 2026-10-29 audit), as `KpiService::forSectionsWithin()` splits the rows. Null only for a
         * row not built by the service.
         */
        public ?int $sectionId = null,
        /**
         * The `week_start` dates (Y-m-d) in the window whose roll call says make-up requested and whose practice holds
         * no make-up yet (`KpiService::owesMakeup()`), oldest first: the leader's "owes a make-up" list.
         */
        public array $makeupOwedWeeks = [],
    ) {}

    public function atRisk(): bool
    {
        return $this->missed >= self::AT_RISK_MISSES;
    }
}
