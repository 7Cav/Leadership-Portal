<?php

namespace App\Repositories;

use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use Carbon\CarbonImmutable;

/**
 * One trooper-week against the practice their primary section held that week (AD-14) — the raw
 * fact the KPI service (5.1) reads. `status` is null while the practice is unmarked for this
 * trooper; `rollcall` is `Unsigned` until they sign, including when they have no attendance row
 * at all.
 * `held` is true once any attendance row on this practice carries a leader's SP mark (any
 * `status` but the LOA-derived `loa`) — a row with only rollcall signatures has not been held, and
 * neither has one whose only marks are automatic makeups (a practice attended elsewhere).
 */
final readonly class CountingMark
{
    public function __construct(
        public int $trooperId,
        public CarbonImmutable $weekStart,
        public ?AttendanceStatus $status,
        public RollcallSignature $rollcall,
        public bool $held,
        /** Who recorded the SP mark — `manual` or `auto` — null while unmarked (2.8's cell source). */
        public ?AttendanceSource $source = null,
    ) {}
}
