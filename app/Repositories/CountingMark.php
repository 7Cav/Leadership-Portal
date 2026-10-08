<?php

namespace App\Repositories;

use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use Carbon\CarbonImmutable;

/**
 * One trooper-week against the practice their primary section held that week (AD-14) — the raw
 * fact the KPI service (5.1) reads. `status` is null while the practice is unmarked for this
 * trooper; `rollcall` is `Unsigned` when they have no attendance row at all.
 */
final readonly class CountingMark
{
    public function __construct(
        public int $trooperId,
        public CarbonImmutable $weekStart,
        public ?AttendanceStatus $status,
        public RollcallSignature $rollcall,
    ) {}
}
