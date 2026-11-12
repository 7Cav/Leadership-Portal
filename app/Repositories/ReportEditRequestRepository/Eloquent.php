<?php

namespace App\Repositories\ReportEditRequestRepository;

use App\Models\ReportEditRequest;
use App\Models\StaffReport;
use App\Models\WeeklyReport;
use App\Repositories\ReportEditRequestRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class Eloquent implements ReportEditRequestRepository
{
    public function find(int $id): ?ReportEditRequest
    {
        return ReportEditRequest::query()->with(['requester', 'unit', 'trooper'])->find($id);
    }

    public function latestFor(int $unitId, ?int $trooperId, CarbonInterface $weekStart): ?ReportEditRequest
    {
        return $this->forReport($unitId, $trooperId, $weekStart)
            ->with(['requester', 'decider'])
            ->latest('id')
            ->first();
    }

    public function hasPending(int $unitId, ?int $trooperId, CarbonInterface $weekStart): bool
    {
        return $this->forReport($unitId, $trooperId, $weekStart)->where('status', ReportEditRequest::PENDING)->exists();
    }

    public function create(int $unitId, ?int $trooperId, CarbonInterface $weekStart, int $requestedBy, ?int $realRequestedBy, string $reason): ?ReportEditRequest
    {
        $weekStart = WeekOf::start($weekStart);

        // The report's own row is the lock two requests at once queue on.
        ($trooperId === null
            ? WeeklyReport::query()->where('unit_id', $unitId)->where('week_start', $weekStart)
            : StaffReport::query()->where('trooper_id', $trooperId)->where('week_start', $weekStart))
            ->lockForUpdate()
            ->first(['id']);

        if ($this->hasPending($unitId, $trooperId, $weekStart)) {
            return null;
        }

        return ReportEditRequest::query()->create([
            'unit_id' => $unitId,
            'trooper_id' => $trooperId,
            'week_start' => $weekStart->toDateString(),
            'requested_by' => $requestedBy,
            'real_requested_by' => $realRequestedBy,
            'reason' => $reason,
        ]);
    }

    public function hasForUnit(int $unitId): bool
    {
        return ReportEditRequest::query()->where('unit_id', $unitId)->exists();
    }

    public function decide(int $id, bool $approve, int $decidedBy, ?string $note, ?CarbonInterface $unlockedUntil): ?ReportEditRequest
    {
        $request = ReportEditRequest::query()->lockForUpdate()->find($id);

        if ($request === null || $request->status !== ReportEditRequest::PENDING) {
            return null;
        }

        $request->forceFill([
            'status' => $approve ? ReportEditRequest::APPROVED : ReportEditRequest::DENIED,
            'decided_by' => $decidedBy,
            'decided_at' => CarbonImmutable::now('UTC'),
            'decision_note' => $note,
            'unlocked_until' => $approve ? $unlockedUntil : null,
        ])->save();

        return $request;
    }

    /** @return Builder<ReportEditRequest> */
    private function forReport(int $unitId, ?int $trooperId, CarbonInterface $weekStart): Builder
    {
        $query = ReportEditRequest::query()->whereDate('week_start', WeekOf::start($weekStart)->toDateString());

        // A staff report is the staff member's whichever HQ they were on; a weekly report is the unit's.
        return $trooperId === null
            ? $query->where('unit_id', $unitId)->whereNull('trooper_id')
            : $query->where('trooper_id', $trooperId);
    }
}
