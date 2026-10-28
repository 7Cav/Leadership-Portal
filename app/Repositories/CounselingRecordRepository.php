<?php

namespace App\Repositories;

use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Models\DriveUploadStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Sole DB access for `counseling_records` (AD-2, #381). Portal-owned (AD-22): the audited `app/Commands/Counseling`
 * Commands are its one writer, plus the Drive upload's status, which the queued upload moves along. The document
 * itself is on the private disk, behind {@see CounselingDocuments}.
 */
interface CounselingRecordRepository
{
    /**
     * Store a new record. Every column is named here, none taken from a request as a whole.
     */
    public function create(
        int $trooperId,
        CounselingType $type,
        CarbonImmutable $counseledOn,
        string $reason,
        int $issuedBy,
        ?int $issuedByAdmin,
        ?int $issuedAsUnit,
        ?int $issuerUnitId,
        StoredDocument $document,
        DriveUploadStatus $driveStatus,
    ): CounselingRecord;

    /** The record with its trooper loaded, or null. */
    public function find(int $id): ?CounselingRecord;

    /**
     * A trooper's records, newest first (by date, then by id), each with its issuer, the Admin who filed it for them
     * and the billet held loaded, so the page names the issuer without a query per record.
     *
     * @return Collection<int, CounselingRecord>
     */
    public function forTrooper(int $trooperId): Collection;

    /** Correct the date and the reason. The type and the document never change. */
    public function update(CounselingRecord $record, CarbonImmutable $counseledOn, string $reason): CounselingRecord;

    /**
     * Delete the record, re-read under a lock in the caller's transaction so the model handed back (and digested by
     * the audit log) is the row as it was deleted. Null when it is already gone.
     */
    public function delete(CounselingRecord $record): ?CounselingRecord;

    /**
     * Set where the record's Drive copy stands, with the Drive file id once uploaded. A record deleted in the meantime
     * is left alone: null.
     */
    public function setDriveStatus(int $id, DriveUploadStatus $status, ?string $driveFileId = null): ?CounselingRecord;
}
