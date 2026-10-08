<?php

use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;

// Pure, app-free unit tests for the one time → week_start mapping (AD-11).
// The Cav week runs from Saturday 00:00 UTC up to (not including) the next Saturday 00:00 UTC.
// 2026-05-30 is a Saturday.

it('returns the same instant for a timestamp exactly at Saturday 00:00 UTC (inclusive start)', function () {
    $weekStart = WeekOf::start(new CarbonImmutable('2026-05-30 00:00:00', 'UTC'));

    expect($weekStart->toIso8601String())->toBe('2026-05-30T00:00:00+00:00');
});

it('returns the prior Saturday for Friday 23:59:59 UTC (inclusive close)', function () {
    $weekStart = WeekOf::start(new CarbonImmutable('2026-06-05 23:59:59', 'UTC'));

    expect($weekStart->toIso8601String())->toBe('2026-05-30T00:00:00+00:00');
});

it('opens a new week one second after the Friday close', function () {
    $weekStart = WeekOf::start(new CarbonImmutable('2026-06-06 00:00:00', 'UTC'));

    expect($weekStart->toIso8601String())->toBe('2026-06-06T00:00:00+00:00');
});

it('returns the opening Saturday for a mid-week time', function () {
    $weekStart = WeekOf::start(new CarbonImmutable('2026-06-02 14:37:12', 'UTC'));

    expect($weekStart->toIso8601String())->toBe('2026-05-30T00:00:00+00:00');
});

it('normalizes a non-UTC input forward across the boundary before bucketing', function () {
    // Friday 21:00 in New York (UTC-4 in June) is Saturday 01:00 UTC — the *next* Cav week.
    $weekStart = WeekOf::start(new CarbonImmutable('2026-06-05 21:00:00', 'America/New_York'));

    expect($weekStart->toIso8601String())->toBe('2026-06-06T00:00:00+00:00');
});

it('normalizes a non-UTC input backward across the boundary before bucketing', function () {
    // Saturday 01:00 in Brussels (UTC+2 in June) is still Friday 23:00 UTC — the *prior* Cav week.
    $weekStart = WeekOf::start(new CarbonImmutable('2026-06-06 01:00:00', 'Europe/Brussels'));

    expect($weekStart->toIso8601String())->toBe('2026-05-30T00:00:00+00:00');
});

it('accepts any DateTimeInterface and leaves the input untouched', function () {
    $input = new DateTime('2026-06-03 08:00:00', new DateTimeZone('Asia/Tokyo'));

    $weekStart = WeekOf::start($input);

    expect($weekStart)->toBeInstanceOf(CarbonImmutable::class)
        ->and($weekStart->toIso8601String())->toBe('2026-05-30T00:00:00+00:00')
        ->and($weekStart->getTimezone()->getName())->toBe('UTC')
        ->and($input->format('Y-m-d H:i:s e'))->toBe('2026-06-03 08:00:00 Asia/Tokyo');
});

it('maps the last microsecond of the week to its Saturday with a zeroed time of day', function () {
    $weekStart = WeekOf::start(new CarbonImmutable('2026-06-05 23:59:59.999999', 'UTC'));

    expect($weekStart->format('Y-m-d H:i:s.u e'))->toBe('2026-05-30 00:00:00.000000 UTC');
});

/**
 * Code-shaped idioms that compute a week boundary: Carbon week helpers, weekday arithmetic and
 * SQL week bucketing. A best-effort tripwire, not a proof — it cannot see every hand-rolled form.
 */
const WEEK_BOUNDARY_IDIOM = '/startOfWeek|endOfWeek|startOf\([\'"]week|isoWeek|weekOfYear|dayOfWeek|::SATURDAY|WeekDay::Saturday|(last|next|previous) saturday|YEARWEEK|DAYOFWEEK|WEEKDAY\(/i';

it('recognizes the week-boundary idioms it guards against', function (string $snippet) {
    expect(preg_match(WEEK_BOUNDARY_IDIOM, $snippet))->toBe(1);
})->with([
    '$t->startOfWeek(Carbon::SATURDAY)',
    "\$t->startOf('week')",
    '$t->subDays($t->dayOfWeek + 1)',
    "\$t->modify('last saturday')",
    'DB::raw("YEARWEEK(practiced_at)")',
    'DAYOFWEEK(created_at)',
]);

it('does not flag a plain mention of the word Saturday', function () {
    expect(preg_match(WEEK_BOUNDARY_IDIOM, '// the Saturday rollcall'))->toBe(0);
});

it('finds no week-boundary computation in app/ or database/ outside WeekOf', function () {
    $root = dirname(__DIR__, 4);
    $weekOf = realpath($root.'/app/Support/Time/WeekOf.php');
    $offenders = [];

    foreach (['app', 'database'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir)) as $file) {
            if ($file->getExtension() !== 'php' || $file->getRealPath() === $weekOf) {
                continue;
            }

            if (preg_match(WEEK_BOUNDARY_IDIOM, file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getPathname();
            }
        }
    }

    expect($offenders)->toBe([], 'Derive week_start through App\Support\Time\WeekOf::start() (AD-11), not inline.');
});
