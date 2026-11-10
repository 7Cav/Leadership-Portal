<?php

use App\Commands\StaffReports\SendStaffReport;
use App\Commands\StaffReports\SendStaffReportHandler;
use App\Models\Trooper;
use App\Models\WeeklyReport;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../StaffPractices/fixture.php';

// The shell's report due banner (`reportsDue`): the reports the signed-in user still owes, on the Saturday they fall due.

beforeEach(function () {
    $this->tree = staffPracticeTree();
    $this->platoon = $this->tree['units']['platoon'];
    $this->pl = Trooper::query()->where('cav_id', $this->tree['users']['pl']->cav_id)->sole();
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
});

it('is null on the Friday the week runs to and on the Sunday after the deadline', function (string $now) {
    Carbon::setTestNow(CarbonImmutable::parse($now, 'UTC'));

    $this->actingAs($this->tree['users']['pl'])->get('/home')->assertInertia(fn (AssertableInertia $page) => $page->where('reportsDue', null));
})->with([
    'Friday' => ['2026-10-09 12:00:00'],
    'Sunday' => ['2026-10-11 12:00:00'],
]);

it('lists the unit\'s weekly report and the staff report while unsent, and drops each once sent', function () {
    $user = $this->tree['users']['pl'];
    $unitHref = route('reports.show', ['unit' => $this->platoon->id, 'week' => '2026-10-03']);
    $staffHref = route('staff-report.show', ['trooper' => $this->pl->id, 'week' => '2026-10-03']);

    $this->actingAs($user)->get('/home')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('reportsDue.due', '2026-10-10T23:59:00+00:00')
        ->where('reportsDue.reports', [
            ['kind' => 'unit', 'label' => '1/A/1-7', 'href' => $unitHref],
            ['kind' => 'staff', 'label' => null, 'href' => $staffHref],
        ]));

    WeeklyReport::factory()->sent()->create(['unit_id' => $this->platoon->id, 'week_start' => CarbonImmutable::parse('2026-10-03', 'UTC')]);

    $this->actingAs($user)->get('/home')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('reportsDue.reports', [['kind' => 'staff', 'label' => null, 'href' => $staffHref]]));

    app(SendStaffReportHandler::class)->handle($user, new SendStaffReport($this->pl->id, CarbonImmutable::parse('2026-10-03', 'UTC'), false, [], presenceType: 'none'));

    $this->actingAs($user)->get('/home')->assertInertia(fn (AssertableInertia $page) => $page->where('reportsDue', null));
});

it('is null for someone who owes no report, and while viewing as someone who does', function () {
    $this->actingAs($this->tree['users']['grant'])->get('/home')->assertInertia(fn (AssertableInertia $page) => $page->where('reportsDue', null));

    $admin = $this->tree['users']['admin'];
    $this->actingAs($admin)->post('/view-as', ['kind' => 'user', 'id' => $this->tree['users']['pl']->id])->assertSessionHasNoErrors();
    $this->actingAs($admin)->get('/home')->assertInertia(fn (AssertableInertia $page) => $page->where('reportsDue', null));
});
