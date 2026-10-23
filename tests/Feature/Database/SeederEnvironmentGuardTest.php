<?php

use App\Models\PortalRoleGrant;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\CaptureReviewSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\GuestPracticeSeeder;
use Database\Seeders\ReportRollupSeeder;
use Database\Seeders\ReportScreenSeeder;

// The demo seeders make sign-in-able users and staff grants over real units. Seeding production
// by mistake must fail loudly and write nothing, while local, testing and e2e (the Playwright
// server's global setup seeds ReportRollupSeeder, ReportScreenSeeder, CaptureReviewSeeder and GuestPracticeSeeder)
// keep working.

it('refuses to seed outside local, testing and e2e, writing nothing', function (string $seeder, string $environment) {
    app()['env'] = $environment;

    expect(fn () => app($seeder)->run())->toThrow(
        RuntimeException::class,
        class_basename($seeder).' creates demo users and grants, so it only runs when APP_ENV is local, testing, e2e; this is "'.$environment.'".',
    );

    expect(User::count())->toBe(0)
        ->and(Unit::count())->toBe(0)
        ->and(PortalRoleGrant::count())->toBe(0);
})->with([ReportRollupSeeder::class, ReportScreenSeeder::class, CaptureReviewSeeder::class, GuestPracticeSeeder::class, DatabaseSeeder::class])->with(['production', 'staging']);

it('seeds the demo data in the environments that exist to be thrown away', function (string $seeder, string $username, string $environment) {
    app()['env'] = $environment;

    app($seeder)->run();

    expect(User::query()->where('username', $username)->exists())->toBeTrue();
})->with([
    'report roll-up' => [ReportRollupSeeder::class, 'Hale.S'],
    'report screen' => [ReportScreenSeeder::class, 'Mercer.J'],
    'capture review' => [CaptureReviewSeeder::class, 'Hartmann.L'],
    'practice guests' => [GuestPracticeSeeder::class, 'Osei.K'],
])->with(['local', 'testing', 'e2e']);

it('runs the default db:seed target', function () {
    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

    expect(User::query()->where('username', 'Test.User')->exists())->toBeTrue();
});
