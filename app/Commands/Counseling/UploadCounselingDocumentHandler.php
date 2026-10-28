<?php

namespace App\Commands\Counseling;

use App\Integration\GoogleDrive\GoogleDriveApi;
use App\Integration\GoogleDrive\GoogleDriveUnavailable;
use App\Models\DriveUploadStatus;
use App\Repositories\CounselingDocuments;
use App\Repositories\CounselingRecordRepository;

/**
 * Upload one counseling record's document to the configured Google Drive folder (#381, AD-3) and note the outcome on
 * the record: `uploaded` with the Drive file id, `skipped` when Drive is not configured (the record stands on its own),
 * or `failed`, rethrown so the queued job tries again. A record deleted before its upload ran, or already uploaded,
 * is left alone, so a retry never uploads twice after a success it saw.
 *
 * The status is the upload's own bookkeeping, like a sync run's, not a personnel action: it is not audited (AD-16's
 * carve-out for system jobs). Filing, correcting and deleting the record are.
 */
class UploadCounselingDocumentHandler
{
    public function __construct(
        private readonly CounselingRecordRepository $records,
        private readonly CounselingDocuments $documents,
        private readonly GoogleDriveApi $drive,
    ) {}

    /**
     * @throws GoogleDriveUnavailable when Drive did not take the file; the record is marked `failed` first
     */
    public function handle(UploadCounselingDocument $command): ?DriveUploadStatus
    {
        $record = $this->records->find($command->recordId);

        if ($record === null || $record->drive_status === DriveUploadStatus::Uploaded) {
            return $record?->drive_status;
        }

        if (! $this->drive->isConfigured()) {
            return $this->records->setDriveStatus($record->id, DriveUploadStatus::Skipped)?->drive_status;
        }

        try {
            $fileId = $this->drive->upload($record->fileName($record->trooper), $this->documents->contents($record->document_path));
        } catch (GoogleDriveUnavailable $e) {
            $this->records->setDriveStatus($record->id, DriveUploadStatus::Failed);

            throw $e;
        }

        return $this->records->setDriveStatus($record->id, DriveUploadStatus::Uploaded, $fileId)?->drive_status;
    }
}
