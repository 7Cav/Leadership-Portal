<?php

namespace App\Commands\Counseling;

use App\Models\CounselingKind;
use App\Models\CounselingType;
use Carbon\CarbonImmutable;

/**
 * Save a draft as it stands, from any step (#565): its type and kind, the Date of Letter (the meeting date, unset until
 * the leader gives it), the leader's fields, and whether the meeting took place. Handled by {@see SaveCounselingDraftHandler}.
 */
final readonly class SaveCounselingDraft
{
    public function __construct(
        public int $recordId,
        public CounselingType $type,
        public CounselingKind $kind,
        public ?CarbonImmutable $counseledOn,
        public CounselingForm $form,
        public bool $meetingConfirmed,
    ) {}
}
