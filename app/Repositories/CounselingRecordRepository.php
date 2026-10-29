<?php

namespace App\Repositories;

use App\Models\CounselingKind;
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
        CounselingKind $kind,
        CarbonImmutable $counseledOn,
        string $reason,
        int $issuedBy,
        ?int $issuedByAdmin,
        ?int $issuedAsUnit,
        ?int $issuerUnitId,
        StoredDocument $document,
        DriveUploadStatus $driveStatus,
    ): CounselingRecord;

    /**
     * Whether the trooper already has a record of `$type` with this date, reason and document (by SHA-256) filed at or
     * after `$since`: a double submit. Called inside the filing's transaction, it first locks the trooper's row, so two
     * filings on one trooper run one after the other and the second sees the first; the read itself is a locking one,
     * so it sees the latest committed rows whatever snapshot the transaction holds.
     */
    public function filedSince(int $trooperId, CounselingType $type, CarbonImmutable $counseledOn, string $reason, string $sha256, CarbonImmutable $since): bool;

    /** The record with its trooper loaded, or null. */
    public function find(int $id): ?CounselingRecord;

    /**
     * A trooper's records, newest first (by date, then by id), each with its issuer, the Admin who filed it for them
     * and the billet held loaded, and the same for whoever overturned it, so the page names them without a query per
     * record.
     *
     * @return Collection<int, CounselingRecord>
     */
    public function forTrooper(int $trooperId): Collection;

    /** Correct the date and the reason. The type and the document never change. */
    public function update(CounselingRecord $record, CarbonImmutable $counseledOn, string $reason): CounselingRecord;

    /**
     * Mark an LOR overturned (S6, 2026-10-29): when, by whom (with the one acting as them, and the billet held, as on a
     * filing) and why. Re-read under a lock in the caller's transaction, so two overturns at once cannot both land: null
     * when the record is gone or already overturned. Nothing else on the record changes.
     */
    public function overturn(CounselingRecord $record, CarbonImmutable $at, int $by, ?int $byAdmin, ?int $asUnit, string $reason): ?CounselingRecord;

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
