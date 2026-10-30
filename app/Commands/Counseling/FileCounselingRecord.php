<?php

namespace App\Commands\Counseling;

use App\Models\CounselingKind;
use App\Models\CounselingType;
use Carbon\CarbonImmutable;

/**
 * A leader files a Developmental Counseling Statement or Letter of Reprimand on a trooper (#381), with the signed
 * document attached. `$documentPath` is the upload's temporary file on this server; `$documentName` is the name it
 * came with, kept for display only. `$kind` says whether it is for a missed section practice or rollcall or for other
 * conduct, which decides the ladder of the regulations it is weighed on. With `$form` the portal makes the document
 * itself from the regiment's form, and `$documentPath` and `$documentName` are not read. Handled by {@see FileCounselingRecordHandler}.
 */
final readonly class FileCounselingRecord
{
    public function __construct(
        public int $trooperId,
        public CounselingType $type,
        public CounselingKind $kind,
        public CarbonImmutable $counseledOn,
        public string $reason,
        public string $documentPath,
        public string $documentName,
        public ?CounselingForm $form = null,
    ) {}
}
