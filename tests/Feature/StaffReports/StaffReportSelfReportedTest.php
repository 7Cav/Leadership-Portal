<?php

use App\Commands\StaffReports\SendStaffReport;
use App\Commands\StaffReports\SendStaffReportHandler;
use App\Models\Game;
use App\Models\TelemetryInterface;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../StaffPractices/fixture.php';

// A game set to "None (impossible)" can never be read automatically (Battlefield 6, DCS World): the staff report page,
// the overview and the unit report each say so once (`selfReported`, the game's name), and the page leaves out the
// "No game server data" alerts. Plain "None" means not set up yet and keeps the alerts: no notice.

beforeEach(function () {
    $this->tree = staffPracticeTree();
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    $this->psg = $this->tree['troopers']['psg'];
});

function playing(object $test, TelemetryInterface $interface): Game
{
    $game = Game::factory()->create(['slug' => 'bf6', 'name' => 'Battlefield 6', 'interface' => $interface]);
    $test->tree['units']['company']->forceFill(['game_id' => $game->id])->save();

    app(SendStaffReportHandler::class)->handle($test->tree['users']['psg'], new SendStaffReport(
        $test->psg->id, CarbonImmutable::parse('2026-10-03', 'UTC'), true, [$test->tree['units']['host']->id], presenceType: 'public_server',
    ));

    return $game;
}

/** What each report page says about the game: the staff report, the overview's group, the unit report's panel. */
function selfReportedProps(object $test): array
{
    $co = $test->tree['users']['co'];
    $props = [];

    $test->actingAs($co)->get(route('staff-report.show', ['trooper' => $test->psg->id, 'week' => '2026-10-03']))
        ->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props) {
            $props['page'] = $page->toArray()['props']['selfReported'];
        });
    $test->actingAs($co)->get('/staff-reports?week=2026-10-03')
        ->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props) {
            $props['overview'] = $page->toArray()['props']['groups'][0]['selfReported'];
        });
    $test->actingAs($co)->get(route('reports.show', ['unit' => $test->tree['units']['platoon']->id, 'week' => '2026-10-03']))
        ->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props) {
            $props['report'] = $page->toArray()['props']['staffSelfReported'];
        });

    return $props;
}

it('names a game that can never be read on every report page, once', function () {
    playing($this, TelemetryInterface::Impossible);

    expect(selfReportedProps($this))->toBe(['page' => 'Battlefield 6', 'overview' => 'Battlefield 6', 'report' => 'Battlefield 6']);
});

it('says nothing for a game whose reading is just not set up yet, or one that is read', function (TelemetryInterface $interface) {
    playing($this, $interface);

    expect(selfReportedProps($this))->toBe(['page' => null, 'overview' => null, 'report' => null]);
})->with([
    'none' => [TelemetryInterface::None],
    'crcon' => [TelemetryInterface::Crcon],
]);

it('still freezes the checks on the report as no data, so switching the game back shows them again', function () {
    playing($this, TelemetryInterface::Impossible);

    $this->actingAs($this->tree['users']['co'])->get(route('staff-report.show', ['trooper' => $this->psg->id, 'week' => '2026-10-03']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.presence', ['check' => 'no_data', 'source' => 'game server'])
            ->where('report.publicServer.check', 'no_data')
            ->where('report.publicServer.reason', 'no_server'));
});
