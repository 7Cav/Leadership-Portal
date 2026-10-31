<?php

namespace App\Repositories\CounselingRecordRepository;

use App\Models\AppealOutcome;
use App\Models\CounselingKind;
use App\Models\CounselingRecord;
use App\Models\CounselingSource;
use App\Models\CounselingStatus;
use App\Models\CounselingType;
use App\Repositories\CounselingRecordRepository;
use App\Repositories\StoredDocument;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;

class Eloquent implements CounselingRecordRepository
{
    /** What a page names on each record, loaded with it. */
    private const NAMED = ['issuedBy', 'issuedByAdmin', 'issuedAsUnit', 'respondedBy', 'appealDecidedBy', 'appealDecidedByAdmin', 'overturnedBy', 'overturnedByAdmin', 'overturnedAsUnit'];

    public function startDraft(int $trooperId, CounselingType $type, CounselingKind $kind, int $issuedBy, ?int $issuedByAdmin, ?int $issuedAsUnit, ?int $issuerUnitId): CounselingRecord
    {
        $record = new CounselingRecord;
        $record->forceFill([
            'trooper_id' => $trooperId,
            'type' => $type,
            'kind' => $kind,
            'status' => CounselingStatus::Draft,
            'source' => CounselingSource::Portal,
            'version' => 1,
            'issued_by' => $issuedBy,
            'issued_by_admin' => $issuedByAdmin,
            'issued_as_unit' => $issuedAsUnit,
            'issuer_unit_id' => $issuerUnitId,
            'form_data' => [],
        ])->save();

        return $record->refresh();
    }

    public function saveDraft(CounselingRecord $record, CounselingType $type, CounselingKind $kind, ?CarbonImmutable $counseledOn, array $formData, bool $meetingConfirmed): ?CounselingRecord
    {
        return $this->change($record, fn (CounselingRecord $locked) => $locked->isDraft(), fn (CounselingRecord $locked) => [
            'type' => $type,
            'kind' => $kind,
            'counseled_on' => $counseledOn?->toDateString(),
            // The trooper's fields stay where a withdrawal left them: none, since it clears them.
            'form_data' => [...($locked->form_data ?? []), ...$formData],
            'meeting_confirmed' => $meetingConfirmed,
        ]);
    }

    public function invite(CounselingRecord $record, array $windows, CarbonImmutable $at): ?CounselingRecord
    {
        return $this->change($record, fn (CounselingRecord $locked) => $locked->isDraft(), fn () => [
            'status' => CounselingStatus::Invited,
            'meeting' => ['windows' => array_values($windows)],
            'invited_at' => $at,
        ]);
    }

    public function share(CounselingRecord $record, array $snapshot, CarbonImmutable $at, ?int $appealUnitId): ?CounselingRecord
    {
        return $this->change($record, fn (CounselingRecord $locked) => $locked->isDraft(), fn () => [
            'status' => CounselingStatus::Shared,
            'snapshot' => $snapshot,
            'shared_at' => $at,
            'appeal_unit_id' => $appealUnitId,
        ]);
    }

    public function withdraw(CounselingRecord $record, CarbonImmutable $at, string $reason): ?CounselingRecord
    {
        return $this->change(
            $record,
            fn (CounselingRecord $locked) => $locked->isShared() && ! $locked->isAppealed(),
            fn (CounselingRecord $locked) => [
                // Back to a draft the leader can invite on again; the trooper's part, if any, is dropped with the share.
                'status' => CounselingStatus::Draft,
                'snapshot' => null,
                'shared_at' => null,
                'appeal_unit_id' => null,
                'responded_at' => null,
                'responded_by' => null,
                'form_data' => array_diff_key($locked->form_data ?? [], array_flip(['plan_trooper', 'comments_trooper', 'appeal'])),
                'withdrawn_at' => $at,
                'withdraw_reason' => $reason,
            ],
        );
    }

    public function respond(CounselingRecord $record, CarbonImmutable $at, int $by, array $trooperFields, ?string $appealStatement): ?CounselingRecord
    {
        return $this->change(
            $record,
            fn (CounselingRecord $locked) => $locked->isShared() && ! $locked->isResponded(),
            fn (CounselingRecord $locked) => [
                'form_data' => [...($locked->form_data ?? []), ...$trooperFields],
                'responded_at' => $at,
                'responded_by' => $by,
                ...($appealStatement !== null ? ['appealed_at' => $at, 'appealed_by' => $by, 'appeal_statement' => $appealStatement] : []),
            ],
        );
    }

    public function decideAppeal(CounselingRecord $record, CarbonImmutable $at, int $by, ?int $byAdmin, AppealOutcome $outcome, string $reason, array $authority, ?array $amended): ?CounselingRecord
    {
        return $this->change(
            $record,
            fn (CounselingRecord $locked) => $locked->isAppealPending($at),
            function (CounselingRecord $locked) use ($at, $by, $byAdmin, $outcome, $reason, $authority, $amended) {
                $form = $locked->form_data ?? [];

                return [
                    'appeal_decided_at' => $at,
                    'appeal_decided_by' => $by,
                    'appeal_decided_by_admin' => $byAdmin,
                    'appeal_outcome' => $outcome,
                    'appeal_decision_reason' => $reason,
                    'appeal_authority' => $authority,
                    // The consequence as first written stays in the record's history.
                    'appeal_original' => $amended === null ? null : array_intersect_key($form, $amended),
                    'form_data' => $amended === null ? $form : [...$form, ...$amended],
                ];
            },
        );
    }

    public function overturn(CounselingRecord $record, CarbonImmutable $at, int $by, ?int $byAdmin, ?int $asUnit, string $reason): ?CounselingRecord
    {
        return $this->change($record, fn (CounselingRecord $locked) => ! $locked->isOverturned(), fn () => [
            'overturned_at' => $at,
            'overturned_by' => $by,
            'overturned_by_admin' => $byAdmin,
            'overturned_as_unit' => $asUnit,
            'overturn_reason' => $reason,
        ]);
    }

    public function delete(CounselingRecord $record): ?CounselingRecord
    {
        $locked = CounselingRecord::query()->lockForUpdate()->find($record->id);
        $locked?->delete();

        return $locked;
    }

    public function createImported(int $trooperId, CounselingType $type, CounselingKind $kind, CarbonImmutable $counseledOn, int $issuedBy, ?int $issuerUnitId, StoredDocument $document, string $importKey, bool $needsReview, ?array $formData): CounselingRecord
    {
        // Shared and acknowledged on the day it carries, at the start of it, so its windows run from that date.
        $on = $counseledOn->utc()->startOfDay();

        $record = new CounselingRecord;
        $record->forceFill([
            'trooper_id' => $trooperId,
            'type' => $type,
            'kind' => $kind,
            'status' => CounselingStatus::Shared,
            'source' => CounselingSource::Import,
            'version' => 1,
            'needs_review' => $needsReview,
            'import_key' => $importKey,
            'counseled_on' => $on->toDateString(),
            'issued_by' => $issuedBy,
            'issuer_unit_id' => $issuerUnitId,
            'document_path' => $document->path,
            'document_name' => $document->name,
            'document_size' => $document->size,
            'document_sha256' => $document->sha256,
            'form_data' => $formData,
            'meeting_confirmed' => true,
            'shared_at' => $on,
            'responded_at' => $on,
        ])->save();

        return $record->refresh();
    }

    public function importedKeys(array $keys): array
    {
        return $keys === [] ? [] : CounselingRecord::query()->whereIn('import_key', $keys)->pluck('import_key')->all();
    }

    public function review(CounselingRecord $record, CounselingType $type, CounselingKind $kind, CarbonImmutable $counseledOn, array $formData): ?CounselingRecord
    {
        return $this->change($record, fn () => true, fn (CounselingRecord $locked) => [
            'type' => $type,
            'kind' => $kind,
            'counseled_on' => $counseledOn->toDateString(),
            'form_data' => [...($locked->form_data ?? []), ...$formData],
            'needs_review' => false,
        ]);
    }

    public function find(int $id): ?CounselingRecord
    {
        return CounselingRecord::query()->with(['trooper', ...self::NAMED])->find($id);
    }

    public function forTrooper(int $trooperId): Collection
    {
        return CounselingRecord::query()
            ->with(self::NAMED)
            ->where('trooper_id', $trooperId)
            ->orderByRaw("case when status = 'shared' then 1 else 0 end")
            ->orderByDesc('counseled_on')
            ->orderByDesc('id')
            ->get();
    }

    public function appealsFor(int $unitId, int $decidedDays = 90): Collection
    {
        return CounselingRecord::query()
            ->with(['trooper', ...self::NAMED])
            ->where('appeal_unit_id', $unitId)
            ->where('type', CounselingType::Lor->value)
            ->whereNotNull('appealed_at')
            ->where(fn ($query) => $query->whereNull('appeal_decided_at')->orWhere('appeal_decided_at', '>=', CarbonImmutable::now()->subDays($decidedDays)))
            ->orderByRaw('case when appeal_decided_at is null then 0 else 1 end')
            ->orderBy('appealed_at')
            ->get();
    }

    public function pendingAppealCount(int $unitId, CarbonImmutable $now): int
    {
        return CounselingRecord::query()
            ->where('appeal_unit_id', $unitId)
            ->where('type', CounselingType::Lor->value)
            ->whereNull('appeal_decided_at')
            ->whereNull('overturned_at')
            ->where('appealed_at', '>', $now->subHours(CounselingRecord::APPEAL_DECISION_HOURS))
            ->count();
    }

    /**
     * Re-read `$record` under a lock, check it is still where `$expected` wants it, and write what `$fields` gives,
     * raising its version. Null when it is gone or has moved on.
     *
     * @param  Closure(CounselingRecord): bool  $expected
     * @param  Closure(CounselingRecord): array<string, mixed>  $fields
     */
    private function change(CounselingRecord $record, Closure $expected, Closure $fields): ?CounselingRecord
    {
        $locked = CounselingRecord::query()->lockForUpdate()->find($record->id);
        if ($locked === null || ! $expected($locked)) {
            return null;
        }

        $locked->forceFill([...$fields($locked), 'version' => $locked->version + 1])->save();

        return $locked->refresh()->load(['trooper', ...self::NAMED]);
    }
}
