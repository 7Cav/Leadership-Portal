<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

// Story 1.15: the home week bar is real dates, not mock data. The week comes from WeekOf (AD-11),
// the only time → week_start mapping, and the report falls due at the end of the Saturday that
// closes it.

afterEach(fn () => CarbonImmutable::setTestNow());

it('passes the current Cav week and its report deadline to the home page', function () {
    CarbonImmutable::setTestNow('2026-10-08 16:20:30', 'UTC'); // a Thursday

    $this->actingAs(User::factory()->create())
        ->get('/home')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Home')
            ->where('week.start', '2026-10-03T00:00:00+00:00')
            ->where('week.reportDue', '2026-10-10T23:59:00+00:00')
        );
});

it('rolls over to the new week at Saturday 00:00 UTC, not on the viewer clock', function () {
    CarbonImmutable::setTestNow('2026-10-10 00:00:00', 'UTC');

    $this->actingAs(User::factory()->create())
        ->get('/home')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('week.start', '2026-10-10T00:00:00+00:00')
            ->where('week.reportDue', '2026-10-17T23:59:00+00:00')
        );
});

it('keeps the week on the boundary-crossing Friday night in UTC', function () {
    // 23:59:59 Friday UTC is still the old week, whatever the viewer's offset.
    CarbonImmutable::setTestNow('2026-10-09 23:59:59', 'UTC');

    $this->actingAs(User::factory()->create())
        ->get('/home')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('week.start', '2026-10-03T00:00:00+00:00')
        );
});

it('still sends a guest to login', function () {
    $this->get('/home')->assertRedirect(route('login'));
});
