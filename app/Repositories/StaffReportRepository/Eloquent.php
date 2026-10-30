<?php

namespace App\Repositories\StaffReportRepository;

use App\Models\StaffReport;
use App\Repositories\StaffReportRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

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
        $fields = array_intersect_key($fields, array_flip(['practice_type', 'ftx_name', 'ftx_date', 'office_hour_date', 'office_hour_note', 'other_events', 'notes', 'presence_check', 'presence_source']));

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

    public function findById(int $id): ?StaffReport
    {
        return StaffReport::query()->find($id);
    }

    public function markPushed(int $id): ?StaffReport
    {
        $row = StaffReport::query()->find($id);

        if ($row === null) {
            return null;
        }

        // Not mass-assignable: only this bookkeeping writes it.
        $row->forceFill(['sheet_pushed_at' => CarbonImmutable::now('UTC')])->save();

        return $row;
    }
}
