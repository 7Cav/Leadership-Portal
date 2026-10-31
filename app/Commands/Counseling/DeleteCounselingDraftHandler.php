<?php

namespace App\Commands\Counseling;

use App\Models\CounselingRecord;
use App\Models\User;
use App\Repositories\CounselingRecordRepository;
use App\Support\Audit\AuditEnvelope;

/**
 * Delete a draft (#565): only a draft, and only by its issuer, their chain or an Admin
 * ({@see CounselingRecordLoader::draft()}). A shared record is never deleted; it is withdrawn back to draft first. The
 * delete is the one trace it leaves (`counseling.draft_deleted`, AD-16), with no reason asked: nobody but the chain
 * ever saw the draft.
 */
class DeleteCounselingDraftHandler
{
    public function __construct(
        private readonly CounselingRecordLoader $loader,
        private readonly CounselingRecordRepository $records,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws CounselingRecordNotFoundException
     * @throws CounselingForbiddenException
     */
    public function handle(User $actor, DeleteCounselingDraft $command): CounselingRecord
    {
        [$record] = $this->loader->draft($actor, $command->recordId);

        return $this->audit->record(
            actorId: $actor->id,
            action: 'counseling.draft_deleted',
            reason: null,
            reasonRequired: false,
            mutation: fn () => $this->records->delete($record) ?? throw new CounselingRecordNotFoundException,
        );
    }
}
