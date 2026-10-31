<?php

namespace App\Commands\Counseling;

use App\Models\CounselingKind;
use App\Models\CounselingType;
use Carbon\CarbonImmutable;

/**
 * Fill in the fields of a record kept with its source PDF (#565): an upload from before records were written in the
 * portal, or an import, flagged for review or not. The type, kind and Date of Letter, the leader's fields
 * (`$form`) and the trooper's (`$response`) as the PDF carries them. Handled by {@see ReviewCounselingRecordHandler}.
 */
final readonly class ReviewCounselingRecord
{
    public function __construct(
        public int $recordId,
        public CounselingType $type,
        public CounselingKind $kind,
        public CarbonImmutable $counseledOn,
        public CounselingForm $form,
        public CounselingResponse $response,
    ) {}
}
