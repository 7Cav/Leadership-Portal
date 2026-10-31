<?php

namespace App\Commands\Counseling;

use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Models\User;
use App\Repositories\CounselingRecordRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Save a draft as it stands (#565), from any step: nothing is required, only the length caps and the date's bounds hold.
 * Only the issuer, their chain and Admins, on a draft of a trooper who still serves ({@see CounselingRecordLoader::draft()}).
 * Turning a DCS into an LOR needs the right to file an LOR. A draft is never shared from here: that is
 * {@see ShareCounselingRecordHandler}. One audited action (`counseling.draft_saved`, AD-16); the version goes up.
 */
class SaveCounselingDraftHandler
{
    public function __construct(
        private readonly CounselingRecordLoader $loader,
        private readonly CounselingRecordRepository $records,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws CounselingRecordNotFoundException
     * @throws CounselingForbiddenException
     * @throws InvalidCounselingRecordException
     */
    public function handle(User $actor, SaveCounselingDraft $command): CounselingRecord
    {
        [$record, $permissions] = $this->loader->draft($actor, $command->recordId);

        if ($command->type !== $record->type && ! $permissions->files($command->type)) {
            throw CounselingForbiddenException::file($command->type);
        }

        $counseledOn = $command->counseledOn === null ? null : CounselingRules::checkedDate($command->counseledOn);
        $command->form->check();
        // A DCS drops the LOR fields, whatever the form carried.
        $form = CounselingForm::fromInput($command->form->toArray(), $command->type);

        return $this->audit->record(
            actorId: $actor->id,
            action: 'counseling.draft_saved',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->records->saveDraft(
                record: $record,
                type: $command->type,
                kind: $command->kind,
                counseledOn: $counseledOn,
                formData: $form->toArray(),
                meetingConfirmed: $command->meetingConfirmed,
                // A new type is issued through the command that may issue it (an LOR through platoon command or above),
                // which decides the draft's chain and, once shared, who hears an appeal.
                issuerUnitId: $command->type === $record->type ? $record->issuer_unit_id : $permissions->issuingUnit($command->type),
            ) ?? throw CounselingForbiddenException::notDraft(),
        );
    }

    /** The share checklist for a draft as stored: what is still missing, field => message (#565). */
    public static function missing(CounselingRecord $record): array
    {
        $form = CounselingForm::fromInput($record->form_data ?? [], $record->type);
        $missing = $form->missing($record->type);

        if ($record->type === CounselingType::Lor && $record->invited_at === null) {
            $missing = ['invite' => 'Send the meeting invite.', ...$missing];
        }

        if ($record->counseled_on === null) {
            $missing['counseled_on'] = 'Give the Date of Letter: the day you met.';
        }

        if (! $record->meeting_confirmed) {
            $missing['meeting_confirmed'] = 'Confirm the meeting took place.';
        }

        return $missing;
    }
}
