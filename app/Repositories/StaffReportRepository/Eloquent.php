<?php

namespace App\Repositories\StaffReportRepository;

use App\Models\StaffReport;
use App\Repositories\StaffReportRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Eloquent implements StaffReportRepository
{
    public function find(int $trooperId, CarbonInterface $weekStart): ?StaffReport
    {
        return StaffReport::query()
            ->where('trooper_id', $trooperId)
            ->where('week_start', WeekOf::requireStart($weekStart))
            ->first();
    }

    public function isSent(int $trooperId, CarbonInterface $weekStart): bool
    {
        return StaffReport::query()
            ->where('trooper_id', $trooperId)
            ->where('week_start', WeekOf::requireStart($weekStart))
            ->sharedLock()
            ->exists();
    }

    public function forTroopers(array $trooperIds, CarbonInterface $weekStart): Collection
    {
        if ($trooperIds === []) {
            return collect();
        }

        return StaffReport::query()
            ->whereIn('trooper_id', $trooperIds)
            ->where('week_start', WeekOf::requireStart($weekStart))
            ->get()
            ->keyBy('trooper_id')
            ->toBase();
    }

    public function send(int $trooperId, ?int $unitId, CarbonInterface $weekStart, bool $attended, array $practices, int $sentBy, array $fields = []): ?StaffReport
    {
        $fields = array_intersect_key($fields, array_flip(['practice_type', 'ftx_name', 'ftx_date', 'office_hour_date', 'office_hour_note', 'presence_type', 'other_events', 'notes', 'presence_check', 'presence_source', 'public_server_check']));

        $row = StaffReport::query()->createOrFirst(
            ['trooper_id' => $trooperId, 'week_start' => WeekOf::requireStart($weekStart)],
            [
                'unit_id' => $unitId,
                'attended' => $attended,
                'practices' => $practices,
                ...$fields,
                'sent_at' => CarbonImmutable::now('UTC'),
                'sent_by' => $sentBy,
            ],
        );

        return $row->wasRecentlyCreated ? $row : null;
    }

    /** What every send sets beyond the key: an edit replaces all of it, so a field the new send leaves out is cleared. */
    private const FIELDS = ['practice_type', 'ftx_name', 'ftx_date', 'office_hour_date', 'office_hour_note', 'presence_type', 'other_events', 'notes', 'presence_check', 'presence_source', 'public_server_check'];

    public function lockSent(int $trooperId, CarbonInterface $weekStart): ?StaffReport
    {
        return StaffReport::query()
            ->where('trooper_id', $trooperId)
            ->where('week_start', WeekOf::requireStart($weekStart))
            ->lockForUpdate()
            ->first();
    }

    public function edit(int $trooperId, ?int $unitId, CarbonInterface $weekStart, bool $attended, array $practices, int $sentBy, array $fields = []): ?StaffReport
    {
        return DB::transaction(function () use ($trooperId, $unitId, $weekStart, $attended, $practices, $sentBy, $fields) {
            $row = $this->lockSent($trooperId, $weekStart);

            if ($row === null) {
                return null;
            }

            $previous = [
                ...$row->only(['unit_id', 'attended', 'practices', ...self::FIELDS, 'sent_by']),
                'ftx_date' => $row->ftx_date?->toDateString(),
                'office_hour_date' => $row->office_hour_date?->toDateString(),
                'sent_at' => $row->sent_at?->toIso8601String(),
            ];

            $row->forceFill([
                ...array_fill_keys(self::FIELDS, null),
                ...array_intersect_key($fields, array_flip(self::FIELDS)),
                'unit_id' => $unitId,
                'attended' => $attended,
                'practices' => $practices,
                'sent_at' => CarbonImmutable::now('UTC'),
                'sent_by' => $sentBy,
                'revisions' => [...($row->revisions ?? []), $previous],
                // The sheet holds the version replaced: the push runs again and overwrites its row.
                'sheet_pushed_at' => null,
            ])->save();

            return $row;
        });
    }

    public function findById(int $id): ?StaffReport
    {
        return StaffReport::query()->find($id);
    }

    public function markPushed(int $id, ?string $range = null): ?StaffReport
    {
        $row = StaffReport::query()->find($id);

        if ($row === null) {
            return null;
        }

        // Not mass-assignable: only this bookkeeping writes it.
        $row->forceFill(['sheet_pushed_at' => CarbonImmutable::now('UTC'), 'sheet_range' => $range ?? $row->sheet_range])->save();

        return $row;
    }
}
