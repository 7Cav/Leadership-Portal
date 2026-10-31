<?php

namespace App\Commands\Counseling;

use App\Authorization\CounselingPermissions;
use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Models\User;
use App\Repositories\CounselingRecordRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Fill in the fields of a record kept with its source PDF (#565), and clear its review flag. The record gains only
 * what its PDF already says, so this is a transcription, not a change of the record: it is not locked by it, the
 * source document never changes and stays what downloads, and nothing is sent to anyone.
 *
 * Who may is {@see CounselingPermissions::reviews()}: the issuer, their chain and Admins, on a shared
 * record with a source document of a trooper who still serves, not overturned. Making it an LOR needs the right to file
 * one, as on a draft. Every field keeps the form's length caps and the date its bounds, whatever the request checked.
 * One audited action (`counseling.reviewed`, AD-16); the version goes up.
 */
class ReviewCounselingRecordHandler
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
    public function handle(User $actor, ReviewCounselingRecord $command): CounselingRecord
    {
        [$record, $permissions] = $this->loader->visible($actor, $command->recordId);

        if ($permissions->departed) {
            throw CounselingForbiddenException::departed($record->trooper);
        }

        if (! $permissions->reviews($record)) {
            throw CounselingForbiddenException::review();
        }

        if ($command->type !== $record->type && ! $permissions->files($command->type)) {
            throw CounselingForbiddenException::file($command->type);
        }

        $counseledOn = CounselingRules::checkedDate($command->counseledOn);
        $command->form->check();
        // The appeal choice is the PDF's to say, so it is not required here: only the length caps hold.
        $command->response->check(CounselingType::Dcs);

        // A DCS drops the LOR fields and the appeal choice, whatever the request carried.
        $form = CounselingForm::fromInput($command->form->toArray(), $command->type);
        $response = CounselingResponse::fromInput($command->response->toArray(), $command->type);

        return $this->audit->record(
            actorId: $actor->id,
            action: 'counseling.reviewed',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->records->review(
                record: $record,
                type: $command->type,
                kind: $command->kind,
                counseledOn: $counseledOn,
                formData: [...$form->toArray(), ...$response->toArray()],
            ) ?? throw new CounselingRecordNotFoundException,
        );
    }
}
