<?php

namespace App\Repositories\CounselingRecordRepository;

use App\Models\CounselingKind;
use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Models\DriveUploadStatus;
use App\Models\Trooper;
use App\Repositories\CounselingRecordRepository;
use App\Repositories\StoredDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class Eloquent implements CounselingRecordRepository
{
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
    ): CounselingRecord {
        $record = new CounselingRecord;
        $record->forceFill([
            'trooper_id' => $trooperId,
            'type' => $type,
            'kind' => $kind,
            'counseled_on' => $counseledOn->toDateString(),
            'reason' => $reason,
            'issued_by' => $issuedBy,
            'issued_by_admin' => $issuedByAdmin,
            'issued_as_unit' => $issuedAsUnit,
            'issuer_unit_id' => $issuerUnitId,
            'document_path' => $document->path,
            'document_name' => $document->name,
            'document_size' => $document->size,
            'document_sha256' => $document->sha256,
            'drive_status' => $driveStatus,
        ])->save();

        return $record->refresh();
    }

    public function filedSince(int $trooperId, CounselingType $type, CarbonImmutable $counseledOn, string $reason, string $sha256, CarbonImmutable $since): bool
    {
        Trooper::query()->whereKey($trooperId)->lockForUpdate()->value('id');

        return CounselingRecord::query()
            ->where('trooper_id', $trooperId)
            ->where('type', $type->value)
            ->whereDate('counseled_on', $counseledOn->toDateString())
            ->where('reason', $reason)
            ->where('document_sha256', $sha256)
            ->where('created_at', '>=', $since)
            ->lockForUpdate()
            ->exists();
    }

    public function find(int $id): ?CounselingRecord
    {
        return CounselingRecord::query()->with('trooper')->find($id);
    }

    public function forTrooper(int $trooperId): Collection
    {
        return CounselingRecord::query()
            ->with(['issuedBy', 'issuedByAdmin', 'issuedAsUnit'])
            ->where('trooper_id', $trooperId)
            ->orderByDesc('counseled_on')
            ->orderByDesc('id')
            ->get();
    }

    public function update(CounselingRecord $record, CarbonImmutable $counseledOn, string $reason): CounselingRecord
    {
        $record->forceFill(['counseled_on' => $counseledOn->toDateString(), 'reason' => $reason])->save();

        return $record->refresh();
    }

    public function delete(CounselingRecord $record): ?CounselingRecord
    {
        $locked = CounselingRecord::query()->lockForUpdate()->find($record->id);
        $locked?->delete();

        return $locked;
    }

    public function setDriveStatus(int $id, DriveUploadStatus $status, ?string $driveFileId = null): ?CounselingRecord
    {
        $record = CounselingRecord::query()->find($id);

        $record?->forceFill([
            'drive_status' => $status,
            'drive_file_id' => $driveFileId ?? $record->drive_file_id,
            'drive_attempted_at' => now(),
        ])->save();

        return $record;
    }
}
