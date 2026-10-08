<?php

use App\Models\WeeklyReport;
use App\Models\WeeklyReportStatus;
use Illuminate\Support\Facades\Artisan;

// The report screen's browser-test fixture (5.3): seeds a platoon with report history and prints a
// signed-in session cookie. Local and testing only.

it('seeds six sent weeks and this week\'s draft, and prints a session cookie for the leader', function () {
    expect(Artisan::call('e2e:report-fixture'))->toBe(0);

    $out = json_decode(trim(collect(explode("\n", trim(Artisan::output())))->last()), true);

    expect($out['cookie']['name'])->toBe(config('session.cookie'))
        ->and($out['cookie']['value'])->not->toBeEmpty()
        ->and(WeeklyReport::query()->where('unit_id', $out['section'])->where('status', WeeklyReportStatus::Sent)->count())->toBe(6)
        ->and(WeeklyReport::query()->where('unit_id', $out['section'])->where('status', WeeklyReportStatus::Draft)->count())->toBe(1);
});

it('refuses to run outside local and testing', function (string $env) {
    app()->detectEnvironment(fn () => $env);

    expect(Artisan::call('e2e:report-fixture'))->toBe(1)
        ->and(WeeklyReport::query()->exists())->toBeFalse();
})->with(['production', 'staging']);
