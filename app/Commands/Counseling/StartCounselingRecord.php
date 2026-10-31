<?php

namespace App\Commands\Counseling;

use App\Models\CounselingKind;
use App\Models\CounselingType;

/**
 * A leader starts a DCS or LOR on a trooper as a draft (#565): its type and what it is for. Handled by
 * {@see StartCounselingRecordHandler}.
 */
final readonly class StartCounselingRecord
{
    public function __construct(
        public int $trooperId,
        public CounselingType $type,
        public CounselingKind $kind,
    ) {}
}
