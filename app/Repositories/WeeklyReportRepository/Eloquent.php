<?php

namespace App\Repositories\WeeklyReportRepository;

use App\Models\Unit;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use App\Repositories\WeeklyReportAlreadySentException;
use App\Repositories\WeeklyReportRepository;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
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
     * Update-or-create the unit-week's row under a row lock, refusing a sent one. Two concurrent
     * first writes for the same unit-week can both find no row and both insert; the UNIQUE key
     * rejects the loser, which then retries once and takes the update path against the winner's
     * row — or is refused, if the winner sent it.
     *
     * @param  array<string, mixed>  $values
     */
    private function write(int $unitId, CarbonInterface $weekStart, array $values): WeeklyReport
    {
        $weekStart = $this->assertWeekStart($weekStart);

        if (! Unit::query()->whereKey($unitId)->exists()) {
            throw new InvalidArgumentException("Unit {$unitId} does not exist; a weekly report belongs to a unit.");
        }

        try {
            return $this->writeOnce($unitId, $weekStart, $values);
        } catch (UniqueConstraintViolationException) {
            return $this->writeOnce($unitId, $weekStart, $values);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function writeOnce(int $unitId, CarbonImmutable $weekStart, array $values): WeeklyReport
    {
        return DB::transaction(function () use ($unitId, $weekStart, $values) {
            $report = WeeklyReport::query()
                ->where('unit_id', $unitId)
                ->where('week_start', $weekStart)
                ->lockForUpdate()
                ->first()
                ?? new WeeklyReport(['unit_id' => $unitId, 'week_start' => $weekStart]);

            if ($report->status === WeeklyReportStatus::Sent) {
                throw WeeklyReportAlreadySentException::for($unitId, $weekStart);
            }

            $report->fill($values)->save();

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
