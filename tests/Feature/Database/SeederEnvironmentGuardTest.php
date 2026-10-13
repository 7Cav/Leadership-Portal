<?php

use App\Models\PortalRoleGrant;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReportRollupSeeder;

// The demo seeders make sign-in-able users and staff grants over real units. Seeding production
// by mistake must fail loudly and write nothing, while local, testing and e2e (the Playwright
// server's global setup seeds ReportRollupSeeder) keep working.

it('refuses to seed outside local, testing and e2e, writing nothing', function (string $seeder, string $environment) {
    app()['env'] = $environment;

    expect(fn () => app($seeder)->run())->toThrow(
        RuntimeException::class,
        class_basename($seeder).' creates demo users and grants, so it only runs when APP_ENV is local, testing, e2e; this is "'.$environment.'".',
    );

    expect(User::count())->toBe(0)
        ->and(Unit::count())->toBe(0)
        ->and(PortalRoleGrant::count())->toBe(0);
})->with([ReportRollupSeeder::class, DatabaseSeeder::class])->with(['production', 'staging']);

it('seeds the report roll-up week in the environments that exist to be thrown away', function (string $environment) {
    app()['env'] = $environment;

    app(ReportRollupSeeder::class)->run();

    expect(User::query()->where('username', 'Hale.S')->exists())->toBeTrue();
})->with(['local', 'testing', 'e2e']);
