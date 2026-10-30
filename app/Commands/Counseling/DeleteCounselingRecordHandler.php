<?php

namespace App\Commands\Counseling;

use App\Authorization\CounselingAccess;
use App\Authorization\CounselingPermissions;
use App\Models\CounselingRecord;
use App\Models\User;
use App\Repositories\CounselingDocuments;
use App\Repositories\CounselingRecordRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Audit\ReasonRequiredException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delete a counseling record (#381), on the same terms as a correction ({@see CorrectCounselingRecordHandler}): the
 * issuer's chain of command or an Admin, with a required reason, never on a trooper who has left the Cav, and never a DCS or LOR past its 72-hour
 * appeal window ({@see CounselingPermissions::frozen()}). One audited action (`counseling.deleted`, AD-16)
 * whose digest is the row as it was deleted, its file's SHA-256 included, so the log still proves what was removed.
 *
 * The document on the private disk goes once the row is gone. A file that cannot be removed then is logged by record
 * id and left behind, rather than undoing a delete the log already holds. The copy on Google Drive stays: the portal's
 * Drive access is upload-only, and the Drive folder is the unit's own filing.
 */
class DeleteCounselingRecordHandler
{
    public function __construct(
        private readonly CounselingAccess $access,
        private readonly CounselingRecordRepository $records,
        private readonly CounselingDocuments $documents,
        private readonly AuditEnvelope $audit,
    ) {}

    /**
     * @throws CounselingRecordNotFoundException
     * @throws CounselingForbiddenException
     * @throws ReasonRequiredException
     */
    public function handle(User $actor, DeleteCounselingRecord $command): CounselingRecord
    {
        $record = $this->records->find($command->recordId) ?? throw new CounselingRecordNotFoundException;
        $permissions = $this->access->for($actor, $record->trooper);

        if (! $permissions->seesRecord($record)) {
            throw new CounselingRecordNotFoundException;
        }

        if ($permissions->departed) {
            throw CounselingForbiddenException::departed($record->trooper);
        }

        // Past its 72-hour appeal window a DCS or LOR is final for everyone, Admins included. An overturned one
        // stays on file for good.
        if ($record->isOverturned()) {
            throw CounselingForbiddenException::overturned();
        }

        if (CounselingPermissions::frozen($record)) {
            throw CounselingForbiddenException::frozen($record->type);
        }

        if (! $permissions->changes($record)) {
            throw CounselingForbiddenException::change();
        }

        $deleted = $this->audit->record(
            actorId: $actor->id,
            action: 'counseling.deleted',
            reason: $command->auditReason !== null ? trim($command->auditReason) : null,
            reasonRequired: true,
            mutation: fn () => $this->records->delete($record) ?? throw new CounselingRecordNotFoundException,
        );

        try {
            $this->documents->delete($deleted->document_path);
        } catch (Throwable $e) {
            Log::warning('A deleted counseling record left its document on disk.', ['record' => $deleted->id, 'exception' => class_basename($e)]);
        }

        return $deleted;
    }
}
