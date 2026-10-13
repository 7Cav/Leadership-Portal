<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

// Story 1.15: the home week bar is real dates, not mock data. Week boundaries come from WeekOf
// (AD-11), the only time → week_start mapping. A week's report falls due at 23:59Z on the
// Saturday after the week ends, so on that Saturday the home page still shows the closing week
// (with the deadline as "today") until the deadline passes.

afterEach(fn () => CarbonImmutable::setTestNow());

function homeWeekAt(string $now): Closure
{
    CarbonImmutable::setTestNow($now);

    return fn (string $start, string $due) => test()->actingAs(User::factory()->serving()->create())
        ->get('/home')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Home')
            ->where('week.start', $start)
            ->where('week.reportDue', $due)
        );
}

it('passes the current Cav week and its report deadline to the home page', function () {
    homeWeekAt('2026-10-08 16:20:30')('2026-10-03T00:00:00+00:00', '2026-10-10T23:59:00+00:00'); // a Thursday
});

it('keeps the week on the boundary-crossing Friday night in UTC', function () {
    homeWeekAt('2026-10-09 23:59:59')('2026-10-03T00:00:00+00:00', '2026-10-10T23:59:00+00:00');
});

it('keeps showing the closing week on its due Saturday, so the live deadline is the one counted down', function (string $now) {
    homeWeekAt($now)('2026-10-03T00:00:00+00:00', '2026-10-10T23:59:00+00:00');
})->with(['Saturday 00:00Z' => '2026-10-10 00:00:00', 'Saturday noon' => '2026-10-10 12:00:00', 'one second before' => '2026-10-10 23:58:59']);

it('moves on to the new week once the report deadline passes', function () {
    homeWeekAt('2026-10-10 23:59:00')('2026-10-10T00:00:00+00:00', '2026-10-17T23:59:00+00:00');
});

it('still sends a guest to login', function () {
    $this->get('/home')->assertRedirect(route('login'));
});
