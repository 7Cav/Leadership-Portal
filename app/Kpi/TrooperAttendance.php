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
        /** Signed rollcalls ÷ assigned, non-LOA weeks. */
        public Ratio $rollcall,
        /** Attended practices ÷ held (a leader SP-marked someone), non-LOA practices. */
        public Ratio $practice,
        /** Practices explicitly marked `missed` in the window. */
        public int $missed,
        /** LOA-covered in the `week_start` week. */
        public bool $onLoa,
        /** Makeup marks in the `week_start` week (0 or 1). */
        public int $makeups,
    ) {}

    public function atRisk(): bool
    {
        return $this->missed >= self::AT_RISK_MISSES;
    }
}
