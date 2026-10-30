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
        ?array $formData = null,
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
            'form_data' => $formData,
        ])->save();

        return $record->refresh();
    }

    public function filedSince(int $trooperId, CounselingType $type, CarbonImmutable $counseledOn, string $reason, string $sha256, CarbonImmutable $since, ?array $formData = null): bool
    {
        Trooper::query()->whereKey($trooperId)->lockForUpdate()->value('id');

        $same = CounselingRecord::query()
            ->where('trooper_id', $trooperId)
            ->where('type', $type->value)
            ->whereDate('counseled_on', $counseledOn->toDateString())
            ->where('reason', $reason)
            ->where('created_at', '>=', $since)
            ->lockForUpdate();

        if ($formData === null) {
            return $same->where('document_sha256', $sha256)->exists();
        }

        // A generated PDF carries the time it was made, so its bytes differ on every render: a generated record repeats
        // one with the same form. Compared here rather than in SQL, since JSON equality differs between databases; the
        // window holds a handful of rows at most.
        return $same->whereNotNull('form_data')->get()->contains(fn (CounselingRecord $record) => $record->form_data == $formData);
    }

    public function find(int $id): ?CounselingRecord
    {
        return CounselingRecord::query()->with('trooper')->find($id);
    }

    public function forTrooper(int $trooperId): Collection
    {
        return CounselingRecord::query()
            ->with(['issuedBy', 'issuedByAdmin', 'issuedAsUnit', 'overturnedBy', 'overturnedByAdmin', 'overturnedAsUnit'])
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

    public function overturn(CounselingRecord $record, CarbonImmutable $at, int $by, ?int $byAdmin, ?int $asUnit, string $reason): ?CounselingRecord
    {
        $locked = CounselingRecord::query()->lockForUpdate()->find($record->id);
        if ($locked === null || $locked->isOverturned()) {
            return null;
        }

        $locked->forceFill([
            'overturned_at' => $at,
            'overturned_by' => $by,
            'overturned_by_admin' => $byAdmin,
            'overturned_as_unit' => $asUnit,
            'overturn_reason' => $reason,
        ])->save();

        return $locked->refresh();
    }

    public function appeal(CounselingRecord $record, CarbonImmutable $at, int $by, string $statement): ?CounselingRecord
    {
        $locked = CounselingRecord::query()->lockForUpdate()->find($record->id);
        if ($locked === null || $locked->isAppealed()) {
            return null;
        }

        $fields = ['appealed_at' => $at, 'appealed_by' => $by, 'appeal_statement' => $statement];
        if ($locked->type === CounselingType::Lor && is_array($locked->form_data)) {
            $fields['form_data'] = [...$locked->form_data, 'appeal' => 'do'];
        }

        $locked->forceFill($fields)->save();

        return $locked->refresh();
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
