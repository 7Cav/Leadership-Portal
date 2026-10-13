<?php

namespace App\Repositories\DemoHistoryRepository;

use App\Models\Attendance;
use App\Models\AttendanceSource;
use App\Models\SectionPractice;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\Unit;
use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use App\Repositories\DemoHistoryCounts;
use App\Repositories\DemoHistoryRepository;
use App\Repositories\UnitPath;
use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class Eloquent implements DemoHistoryRepository
{
    /** Rows per INSERT statement — well under SQLite's and MySQL's bound-parameter ceilings. */
    private const INSERT_CHUNK = 500;

    public function subtreeUnits(Unit $root): Collection
    {
        // Depth-first in PHP: a text `orderBy('path')` puts "1/145/" before "1/2/".
        return Unit::query()->where('path', 'like', "{$root->path}%")->get()
            ->sort(fn (Unit $a, Unit $b) => UnitPath::compare($a->path, $b->path))
            ->values();
    }

    public function placedTroopers(Unit $root): Collection
    {
        return Trooper::query()
            ->assignedWithin($root)
            ->orderBy('id')
            ->get(['id', 'primary_section_id'])
            ->groupBy('primary_section_id')
            ->map(fn (Collection $troopers) => $troopers->pluck('id')->all())
            ->toBase();
    }

    public function placedCountsBySection(): Collection
    {
        return Trooper::query()
            ->where('status', '!=', TrooperStatus::Inactive->value)
            ->whereNotNull('primary_section_id')
            ->groupBy('primary_section_id')
            ->toBase()
            ->selectRaw('primary_section_id, count(*) as placed')
            ->pluck('placed', 'primary_section_id')
            ->map(fn ($count) => (int) $count);
    }

    public function weeksWithRealHistory(array $sectionIds, array $unitIds, array $weekStarts): array
    {
        $weeks = array_map(fn (CarbonInterface $week) => WeekOf::requireStart($week), $weekStarts);

        if ($weeks === []) {
            return [];
        }

        $practices = SectionPractice::query()
            ->whereIn('section_id', $sectionIds)
            ->whereIn('week_start', $weeks)
            ->where(fn (Builder $query) => $query
                ->where('is_demo', false)
                ->orWhereHas('attendance', fn (Builder $attendance) => $attendance->where('is_demo', false)))
            ->pluck('week_start');

        $reports = WeeklyReport::query()
            ->whereIn('unit_id', $unitIds)
            ->whereIn('week_start', $weeks)
            ->where('is_demo', false)
            ->pluck('week_start');

        return $practices->merge($reports)
            ->map(fn (CarbonInterface $week) => CarbonImmutable::instance($week)->utc())
            ->unique(fn (CarbonImmutable $week) => $week->getTimestamp())
            ->sortBy(fn (CarbonImmutable $week) => $week->getTimestamp())
            ->values()
            ->all();
    }

    public function clearWeek(array $sectionIds, array $unitIds, CarbonInterface $weekStart): void
    {
        $weekStart = WeekOf::requireStart($weekStart);
        $practices = SectionPractice::query()
            ->whereIn('section_id', $sectionIds)
            ->where('week_start', $weekStart)
            ->where('is_demo', true)
            ->select('id');

        Attendance::query()->where('is_demo', true)->whereIn('section_practice_id', $practices)->delete();
        SectionPractice::query()
            ->whereIn('section_id', $sectionIds)
            ->where('week_start', $weekStart)
            ->where('is_demo', true)
            ->whereDoesntHave('attendance')
            ->delete();
        WeeklyReport::query()
            ->whereIn('unit_id', $unitIds)
            ->where('week_start', $weekStart)
            ->where('is_demo', true)
            ->delete();
    }

    public function createPractices(array $sectionIds, CarbonInterface $weekStart): Collection
    {
        $weekStart = WeekOf::requireStart($weekStart);

        if ($sectionIds === []) {
            return collect();
        }

        $now = CarbonImmutable::now('UTC');

        SectionPractice::query()->insert(array_map(fn (int $sectionId) => [
            'section_id' => $sectionId,
            'week_start' => $weekStart,
            'is_demo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $sectionIds));

        return SectionPractice::query()
            ->whereIn('section_id', $sectionIds)
            ->where('week_start', $weekStart)
            ->get()
            ->keyBy('section_id')
            ->toBase();
    }

    public function insertAttendance(array $rows): int
    {
        $now = CarbonImmutable::now('UTC');

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            Attendance::query()->insert(array_map(fn (array $row) => [
                'trooper_id' => $row['trooper_id'],
                'section_practice_id' => $row['section_practice_id'],
                'status' => $row['status']->value,
                'source' => AttendanceSource::Manual->value,
                'rollcall' => $row['rollcall']->value,
                'is_demo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        return count($rows);
    }

    public function createSentReport(
        int $unitId,
        CarbonInterface $weekStart,
        array $kpis,
        ?string $notes,
        int $sentBy,
        string $contentDigest,
    ): WeeklyReport {
        $report = new WeeklyReport;
        $report->forceFill([
            'unit_id' => $unitId,
            'week_start' => WeekOf::requireStart($weekStart),
            'status' => WeeklyReportStatus::Sent,
            'kpis' => $kpis,
            'notes' => $notes,
            'sent_at' => CarbonImmutable::now('UTC'),
            'sent_by' => $sentBy,
            'content_digest' => $contentDigest,
            'is_demo' => true,
        ])->save();

        return $report;
    }

    public function count(?array $sectionIds = null, ?array $unitIds = null): DemoHistoryCounts
    {
        $practices = SectionPractice::query()->where('is_demo', true)
            ->when($sectionIds !== null, fn (Builder $query) => $query->whereIn('section_id', $sectionIds));
        $attendance = Attendance::query()->where('is_demo', true)
            ->when($sectionIds !== null, fn (Builder $query) => $query->whereIn(
                'section_practice_id',
                SectionPractice::query()->whereIn('section_id', $sectionIds)->select('id'),
            ));
        $reports = WeeklyReport::query()->where('is_demo', true)
            ->when($unitIds !== null, fn (Builder $query) => $query->whereIn('unit_id', $unitIds));

        $weeks = (clone $practices)->distinct()->pluck('week_start')
            ->merge((clone $reports)->distinct()->pluck('week_start'))
            ->unique(fn (CarbonInterface $week) => $week->getTimestamp())
            ->count();

        return new DemoHistoryCounts($practices->count(), $attendance->count(), $reports->count(), $weeks);
    }

    public function purge(array $sectionIds, array $unitIds): DemoHistoryCounts
    {
        $weeks = $this->count($sectionIds, $unitIds)->weeks;

        $attendance = Attendance::query()
            ->where('is_demo', true)
            ->whereIn('section_practice_id', SectionPractice::query()->whereIn('section_id', $sectionIds)->select('id'))
            ->delete();
        // A leader may since have marked a real row against a demo practice; that practice stays
        // (the real mark hangs off it, and `restrictOnDelete` would refuse it anyway).
        $practices = SectionPractice::query()
            ->whereIn('section_id', $sectionIds)
            ->where('is_demo', true)
            ->whereDoesntHave('attendance')
            ->delete();
        $reports = WeeklyReport::query()->whereIn('unit_id', $unitIds)->where('is_demo', true)->delete();

        return new DemoHistoryCounts($practices, $attendance, $reports, $weeks);
    }
}
