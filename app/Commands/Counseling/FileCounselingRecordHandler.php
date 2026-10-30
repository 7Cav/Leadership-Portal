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
use Carbon\CarbonImmutable;
use Throwable;

/**
 * File a DCS or LOR on a trooper (#381). Refused before anything is written: an unknown trooper or one the actor cannot
 * see ({@see CounselingRecordNotFoundException}), a trooper who has left the Cav (their records are read-only for
 * everyone, Admins included; 403), an actor whose command does not reach the trooper at the echelon the
 * type needs ({@see CounselingAccess}, 403), a date in the future, no reason, and a document that is not a real PDF of
 * at most 10 MB ({@see CounselingRules}).
 *
 * The document is copied onto the private disk under a name the portal makes up, then the record is inserted in one
 * audited transaction. An identical record (same type, date, reason and document) filed on the trooper in the last
 * {@see CounselingRules::REPEAT_WINDOW_MINUTES} minutes is a double submit: it is refused on the document field, and
 * the copy just stored is removed, so nothing is kept twice and the 90-day count is not inflated. The check runs
 * inside that transaction with the trooper's row locked, so two submits at once cannot both pass it (`counseling.filed`, AD-16; the digest covers the row, which carries the file's SHA-256 and never
 * its bytes). The record's own reason is the record, so the audit entry needs none. If the insert fails, the stored
 * file is removed again. The record names the unit it was filed through, which decides who may later change it.
 *
 * A record filed with the portal's form ({@see CounselingForm}) has its PDF rendered here ({@see CounselingFormPdf}),
 * after the permission checks and the form's own checks, with section 1 read from the roster
 * ({@see CounselingFormParties}). From then on it takes exactly the upload's path: the PDF check, the private copy, the
 * double-submit check (on the form, since a rendered PDF differs byte for byte each time), the audit entry, which says
 * the document was generated in the portal, and the Drive copy. The form is kept on the record (`form_data`).
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
        private readonly CounselingFormParties $parties,
        private readonly CounselingFormPdf $pdf,
    ) {}

    /** The audit entry's note on a record whose document the portal made. */
    public const GENERATED_NOTE = 'Generated in the portal.';

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

        if ($permissions->departed) {
            throw CounselingForbiddenException::departed($trooper);
        }

        if (! $permissions->files($command->type)) {
            throw CounselingForbiddenException::file($command->type);
        }

        [$counseledOn, $reason] = CounselingRules::checked($command->counseledOn, $command->reason);

        $form = $command->form;
        $documentPath = $command->documentPath;
        $documentName = $command->documentName;
        $generated = null;

        if ($form !== null) {
            $form->check($command->type);
            $parties = $this->parties->for($trooper, $actor);
            $generated = $documentPath = $this->pdf->toTemporaryFile($command->type, $counseledOn, $parties, $form);
            $documentName = "{$command->type->short()} {$counseledOn->toDateString()} {$parties['trooper']['name']}.pdf";
        }

        try {
            return $this->file($actor, $command, $trooper->id, $permissions->issuingUnit($command->type), $counseledOn, $reason, $documentPath, $documentName, $form);
        } finally {
            if ($generated !== null) {
                @unlink($generated);
            }
        }
    }

    /**
     * The path an uploaded and a generated document share.
     *
     * @throws InvalidCounselingRecordException
     */
    private function file(User $actor, FileCounselingRecord $command, int $trooperId, ?int $issuerUnitId, CarbonImmutable $counseledOn, string $reason, string $documentPath, string $documentName, ?CounselingForm $form): CounselingRecord
    {
        CounselingRules::checkDocument($documentPath);

        $stored = $this->documents->store($documentPath, CounselingRules::displayName($documentName));
        $formData = $form?->toArray();

        try {
            return $this->audit->record(
                actorId: $actor->id,
                action: 'counseling.filed',
                reason: $form !== null ? self::GENERATED_NOTE : null,
                reasonRequired: false,
                mutation: fn () => $this->filedAgain($trooperId, $command, $counseledOn, $reason, $stored->sha256, $formData)
                    ? throw InvalidCounselingRecordException::alreadyFiled()
                    : $this->records->create(
                        trooperId: $trooperId,
                        type: $command->type,
                        kind: $command->kind,
                        counseledOn: $counseledOn,
                        reason: $reason,
                        issuedBy: $actor->id,
                        issuedByAdmin: $this->acting->adminActingAs($actor->id),
                        issuedAsUnit: $this->acting->billetOf($actor->id),
                        issuerUnitId: $issuerUnitId,
                        document: $stored,
                        driveStatus: $this->drive->isConfigured() ? DriveUploadStatus::Pending : DriveUploadStatus::Skipped,
                        formData: $formData,
                    ),
            );
        } catch (Throwable $e) {
            $this->documents->delete($stored->path);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>|null  $formData
     */
    private function filedAgain(int $trooperId, FileCounselingRecord $command, CarbonImmutable $counseledOn, string $reason, string $sha256, ?array $formData): bool
    {
        return $this->records->filedSince(
            trooperId: $trooperId,
            type: $command->type,
            counseledOn: $counseledOn,
            reason: $reason,
            sha256: $sha256,
            since: CarbonImmutable::now()->subMinutes(CounselingRules::REPEAT_WINDOW_MINUTES),
            formData: $formData,
        );
    }
}
