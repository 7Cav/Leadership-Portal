<?php

namespace App\Commands\Counseling;

use App\Authorization\CounselingAccess;
use App\Integration\GoogleDrive\GoogleDriveApi;
use App\Models\CounselingRecord;
use App\Models\DriveUploadStatus;
use App\Models\User;
use App\Repositories\CounselingDocuments;
use App\Repositories\CounselingRecordRepository;
use App\Repositories\TrooperRepository;
use App\Support\Audit\ActingFor;
use App\Support\Audit\AuditEnvelope;
use Throwable;

/**
 * File a DCS or LOR on a trooper (#381). Refused before anything is written: an unknown trooper or one the actor cannot
 * see ({@see CounselingRecordNotFoundException}), an actor whose command does not reach the trooper at the echelon the
 * type needs ({@see CounselingAccess}, 403), a date in the future, no reason, and a document that is not a real PDF of
 * at most 10 MB ({@see CounselingRules}).
 *
 * The document is copied onto the private disk under a name the portal makes up, then the record is inserted in one
 * audited transaction (`counseling.filed`, AD-16; the digest covers the row, which carries the file's SHA-256 and never
 * its bytes). The record's own reason is the record, so the audit entry needs none. If the insert fails, the stored
 * file is removed again. The record names the unit it was filed through, which decides who may later change it.
 *
 * The Drive copy is not made here: the record starts `pending` when Google Drive is configured, and the caller queues
 * the upload ({@see UploadCounselingDocumentHandler}); without Drive it is `skipped` and the record stands on its own.
 */
class FileCounselingRecordHandler
{
    public function __construct(
        private readonly CounselingAccess $access,
        private readonly TrooperRepository $troopers,
        private readonly CounselingRecordRepository $records,
        private readonly CounselingDocuments $documents,
        private readonly GoogleDriveApi $drive,
        private readonly AuditEnvelope $audit,
        private readonly ActingFor $acting,
    ) {}

    /**
     * @throws CounselingRecordNotFoundException
     * @throws CounselingForbiddenException
     * @throws InvalidCounselingRecordException
     */
    public function handle(User $actor, FileCounselingRecord $command): CounselingRecord
    {
        $trooper = $this->troopers->find($command->trooperId) ?? throw new CounselingRecordNotFoundException;
        $permissions = $this->access->for($actor, $trooper);

        if (! $permissions->seesLor) {
            throw new CounselingRecordNotFoundException;
        }

        if (! $permissions->files($command->type)) {
            throw CounselingForbiddenException::file($command->type);
        }

        [$counseledOn, $reason] = CounselingRules::checked($command->counseledOn, $command->reason);
        CounselingRules::checkDocument($command->documentPath);

        $stored = $this->documents->store($command->documentPath, CounselingRules::displayName($command->documentName));

        try {
            return $this->audit->record(
                actorId: $actor->id,
                action: 'counseling.filed',
                reason: null,
                reasonRequired: false,
                mutation: fn () => $this->records->create(
                    trooperId: $trooper->id,
                    type: $command->type,
                    counseledOn: $counseledOn,
                    reason: $reason,
                    issuedBy: $actor->id,
                    issuedByAdmin: $this->acting->adminActingAs($actor->id),
                    issuedAsUnit: $this->acting->billetOf($actor->id),
                    issuerUnitId: $permissions->issuingUnit($command->type),
                    document: $stored,
                    driveStatus: $this->drive->isConfigured() ? DriveUploadStatus::Pending : DriveUploadStatus::Skipped,
                ),
            );
        } catch (Throwable $e) {
            $this->documents->delete($stored->path);

            throw $e;
        }
    }
}
