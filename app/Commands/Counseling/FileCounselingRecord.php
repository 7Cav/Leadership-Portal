<?php

namespace App\Commands\Counseling;

use App\Models\CounselingType;
use Carbon\CarbonImmutable;

/**
 * A leader files a Developmental Counseling Statement or Letter of Reprimand on a trooper (#381), with the signed
 * document attached. `$documentPath` is the upload's temporary file on this server; `$documentName` is the name it
 * came with, kept for display only. Handled by {@see FileCounselingRecordHandler}.
 */
final readonly class FileCounselingRecord
{
    public function __construct(
        public int $trooperId,
        public CounselingType $type,
        public CarbonImmutable $counseledOn,
        public string $reason,
        public string $documentPath,
        public string $documentName,
    ) {}
}
