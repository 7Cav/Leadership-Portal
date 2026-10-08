<?php

namespace App\Repositories\WeeklyReportRepository;

use App\Models\Unit;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use App\Repositories\WeeklyReportAlreadySentException;
use App\Repositories\WeeklyReportRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Eloquent implements WeeklyReportRepository
{
    public function find(int $unitId, CarbonInterface $weekStart): ?WeeklyReport
    {
        return WeeklyReport::query()
            ->where('unit_id', $unitId)
            ->where('week_start', $this->assertWeekStart($weekStart))
            ->first();
    }

    public function saveDraft(int $unitId, CarbonInterface $weekStart, array $kpis, ?string $notes): WeeklyReport
    {
        return $this->write($unitId, $weekStart, [
            'kpis' => $kpis,
            'notes' => $notes,
        ]);
    }

    public function send(
        int $unitId,
        CarbonInterface $weekStart,
        array $kpis,
        ?string $notes,
        int $sentBy,
        string $contentDigest,
        ?int $templateVersionId,
    ): WeeklyReport {
        if (! User::query()->whereKey($sentBy)->exists()) {
            throw new InvalidArgumentException("User {$sentBy} does not exist; a sent report records its sender.");
        }

        return $this->write($unitId, $weekStart, [
            'status' => WeeklyReportStatus::Sent,
            'kpis' => $kpis,
            'notes' => $notes,
            'sent_at' => CarbonImmutable::now('UTC'),
            'sent_by' => $sentBy,
            'content_digest' => $contentDigest,
            'template_version_id' => $templateVersionId,
        ]);
    }

    /**
     * Update-or-create the unit-week's row, refusing a sent one, in one transaction:
     *
     * 1. Share-lock the `units` row, so the unit cannot be deleted mid-write and a missing unit is
     *    an `InvalidArgumentException` rather than a raw foreign-key error.
     * 2. Insert-or-ignore the `(unit_id, week_start)` row, so a row always exists before it is
     *    locked. A `SELECT ... FOR UPDATE` on a missing key would take only a gap lock on MySQL,
     *    and two first writes holding the same gap deadlock when both insert; insert-or-ignore
     *    instead makes a concurrent first write of the same key wait for this one to commit and
     *    then skip. The placeholder values never outlive the transaction.
     * 3. Lock that row, refuse it if sent, and write `$values` over it.
     *
     * @param  array<string, mixed>  $values  built only by this class, so they are force-filled
     */
    private function write(int $unitId, CarbonInterface $weekStart, array $values): WeeklyReport
    {
        $weekStart = $this->assertWeekStart($weekStart);

        return DB::transaction(function () use ($unitId, $weekStart, $values) {
            if (Unit::query()->whereKey($unitId)->sharedLock()->first() === null) {
                throw new InvalidArgumentException("Unit {$unitId} does not exist; a weekly report belongs to a unit.");
            }

            $now = CarbonImmutable::now('UTC');

            WeeklyReport::query()->insertOrIgnore([
                'unit_id' => $unitId,
                'week_start' => $weekStart,
                'status' => WeeklyReportStatus::Draft->value,
                'kpis' => '[]',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $report = WeeklyReport::query()
                ->where('unit_id', $unitId)
                ->where('week_start', $weekStart)
                ->lockForUpdate()
                ->sole();

            if ($report->status === WeeklyReportStatus::Sent) {
                throw WeeklyReportAlreadySentException::for($unitId, $weekStart);
            }

            $report->forceFill($values)->save();

            return $report;
        });
    }

    /**
     * Validates rather than derives: `$weekStart` must already be the boundary `WeekOf` maps its
     * own instant to (AD-11), so the Saturday 00:00 UTC rule lives in that one helper. Returned
     * as that UTC instant, so the stored and queried value is the same on every driver.
     */
    private function assertWeekStart(CarbonInterface $weekStart): CarbonImmutable
    {
        $boundary = WeekOf::start($weekStart);

        if (! $boundary->equalTo($weekStart)) {
            throw new InvalidArgumentException(
                "week_start must be a Saturday 00:00 UTC (AD-11); got {$weekStart->toIso8601String()}.",
            );
        }

        return $boundary;
    }
}
