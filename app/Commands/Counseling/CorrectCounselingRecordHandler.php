<?php

namespace App\Commands\Counseling;

use App\Authorization\CounselingAccess;
use App\Authorization\CounselingPermissions;
use App\Models\CounselingRecord;
use App\Models\User;
use App\Repositories\CounselingRecordRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Audit\ReasonRequiredException;

/**
 * Correct the date or reason of a counseling record (#381). Only someone who sees the record and commands the unit it
 * was filed through (the issuer's chain), or an Admin, may ({@see CounselingAccess}); a record the actor cannot see
 * is not found, one they see but may not change is a 403, and so is any record of a trooper who has left the Cav, and a DCS or LOR filed 72 hours ago or more
 * ({@see CounselingPermissions::frozen()}); only an LOR may then be overturned, by the Regimental Executive Officer. Saving what is already stored records nothing; a change is
 * one audited action (`counseling.corrected`, AD-16) whose reason is required.
 */
class CorrectCounselingRecordHandler
{
    public function __construct(
        private readonly CounselingAccess $access,
        private readonly CounselingRecordRepository $records,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws CounselingRecordNotFoundException
     * @throws CounselingForbiddenException
     * @throws InvalidCounselingRecordException
     * @throws ReasonRequiredException
     */
    public function handle(User $actor, CorrectCounselingRecord $command): CounselingRecord
    {
        $record = $this->records->find($command->recordId) ?? throw new CounselingRecordNotFoundException;
        $permissions = $this->access->for($actor, $record->trooper);

        if (! $permissions->seesRecord($record)) {
            throw new CounselingRecordNotFoundException;
        }

        if ($permissions->departed) {
            throw CounselingForbiddenException::departed($record->trooper);
        }

        // Past its 72-hour appeal window a DCS or LOR is final for everyone, Admins included.
        if ($record->isOverturned()) {
            throw CounselingForbiddenException::overturned();
        }

        if (CounselingPermissions::frozen($record)) {
            throw CounselingForbiddenException::frozen($record->type);
        }

        if (! $permissions->changes($record)) {
            throw CounselingForbiddenException::change();
        }

        [$counseledOn, $reason] = CounselingRules::checked($command->counseledOn, $command->reason);

        if ($record->counseled_on->toDateString() === $counseledOn->toDateString() && $record->reason === $reason) {
            return $record;
        }

        return $this->audit->record(
            actorId: $actor->id,
            action: 'counseling.corrected',
            reason: $command->auditReason !== null ? trim($command->auditReason) : null,
            reasonRequired: true,
            mutation: fn () => $this->records->update($record, $counseledOn, $reason),
        );
    }
}
