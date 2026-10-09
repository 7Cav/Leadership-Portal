<?php

use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\ReportRollupSeeder;

// The browser test (Story 5.8) runs on this seeder; keep it seeding the week it promises.
it('seeds a company week with five of eight sections sent and last week sent everywhere', function () {
    CarbonImmutable::setTestNow('2026-10-08 16:20:30');
    $this->seed(ReportRollupSeeder::class);

    $thisWeek = WeeklyReport::query()->where('week_start', '2026-10-03 00:00:00')->get();

    expect($thisWeek->where('status', WeeklyReportStatus::Sent))->toHaveCount(5)
        ->and($thisWeek->where('status', WeeklyReportStatus::Draft))->toHaveCount(2)
        ->and(WeeklyReport::query()->where('week_start', '2026-09-26 00:00:00')->where('status', WeeklyReportStatus::Sent)->count())->toBe(8);

    CarbonImmutable::setTestNow();
});
