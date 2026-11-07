<?php

use App\Commands\Capture\CaptureServer;
use App\Commands\Capture\CaptureSource;
use App\Commands\Capture\CaptureSources;
use App\Commands\Capture\CaptureSourceUnavailable;
use App\Commands\StaffReports\SendStaffReport;
use App\Commands\StaffReports\SendStaffReportHandler;
use App\Integration\Crcon\Data\CrconPlayer;
use App\Integration\Crcon\Data\CrconPresence;
use App\Models\GameServer;
use App\Models\GameServerType;
use App\Models\StaffReport;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\User;
use App\Repositories\GameServerRepository;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../StaffPractices/fixture.php';

// A public server hour on the staff report is checked against the game servers marked public, for the game the HQ
// plays: the trooper's minutes over the whole Cav week, summed across those servers, frozen on the report at send.
// 60 or more is confirmed, under is a conflict, nothing to read is no data. The report is sent either way, and an
// office hour or "No" is never checked. CRCON is never called: the servers are fakes.

beforeEach(function () {
    $this->tree = staffPracticeTree();
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));
    $this->psg = $this->tree['troopers']['psg'];
    $this->host = $this->tree['units']['host'];
    $this->tree['units']['company']->forceFill(['game_id' => game('hll')->id])->save();
    TrooperGameIdentity::factory()->create(['trooper_id' => $this->psg->id, 'game_id' => game('hll')->id, 'platform_id' => '76561190000000020']);
    $this->reads = [];
    $this->games = [];
});

/** A fake public server whose one source answers with `$players`, or throws when `$players` is null. */
function publicServer($test, string $label, ?array $players, bool $configured = true): CaptureServer
{
    return new CaptureServer(null, $label, [new CaptureSource('crcon', "crcon:{$label}", function ($start, $end) use ($test, $label, $players) {
        $test->reads[] = [$label, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
        if ($players === null) {
            throw new CaptureSourceUnavailable('crcon: unreachable');
        }

        return new CrconPresence($players, $start, $end, CarbonImmutable::now()->toDateTimeImmutable());
    }, $configured)]);
}

/** @param  list<CaptureServer>  $servers  the public servers every game has */
function publicServers($test, array $servers): void
{
    app()->instance(CaptureSources::class, new CaptureSources([], null, null, function ($game) use ($test, $servers) {
        $test->games[] = $game->slug;

        return $servers;
    }));
}

function sendPublicServerReport(User $user, Trooper $trooper, string $presence, array $more = []): StaffReport
{
    return app(SendStaffReportHandler::class)->handle($user, new SendStaffReport(
        $trooper->id, CarbonImmutable::parse('2026-10-03', 'UTC'), true, [test()->host->id],
        officeHourDate: $more['office_hour_date'] ?? null, officeHourNote: $more['office_hour_note'] ?? null, presenceType: $presence,
    ));
}

function onServer(string $platformId, ?int $minutes): CrconPlayer
{
    return new CrconPlayer('Brandt', $platformId, minutes: $minutes);
}

it('sums the minutes across the public servers over the whole Cav week, and confirms an hour', function () {
    publicServers($this, [
        publicServer($this, 'one', [onServer('76561190000000020', 35), onServer('76561190000000099', 300)]),
        publicServer($this, 'two', [onServer('76561190000000020', 25)]),
    ]);

    $report = sendPublicServerReport($this->tree['users']['psg'], $this->psg, 'public_server');

    expect($report->refresh()->presence_type)->toBe('public_server')
        ->and($report->public_server_check)->toBe(StaffReport::PRESENCE_CONFIRMED)
        ->and($this->reads)->toBe([
            ['one', '2026-10-03 00:00:00', '2026-10-09 23:59:59'],
            ['two', '2026-10-03 00:00:00', '2026-10-09 23:59:59'],
        ])
        ->and($this->games)->toBe(['hll']);
});

it('flags under an hour as a conflict, and still sends the report', function () {
    publicServers($this, [publicServer($this, 'one', [onServer('76561190000000020', 59)])]);

    $report = sendPublicServerReport($this->tree['users']['psg'], $this->psg, 'public_server');

    expect($report->exists)->toBeTrue()->and($report->public_server_check)->toBe(StaffReport::PRESENCE_CONFLICT);
});

it('flags a trooper the public servers never saw as a conflict', function () {
    publicServers($this, [publicServer($this, 'one', [onServer('76561190000000099', 120)])]);

    expect(sendPublicServerReport($this->tree['users']['psg'], $this->psg, 'public_server')->public_server_check)->toBe(StaffReport::PRESENCE_CONFLICT);
});

it('reads only up to now while the week still runs', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-07 18:30:00', 'UTC'));
    publicServers($this, [publicServer($this, 'one', [onServer('76561190000000020', 60)])]);

    expect(sendPublicServerReport($this->tree['users']['psg'], $this->psg, 'public_server')->public_server_check)->toBe(StaffReport::PRESENCE_CONFIRMED)
        ->and($this->reads)->toBe([['one', '2026-10-03 00:00:00', '2026-10-07 18:30:00']]);
});

it('says no data when there is nothing to compare', function (Closure $setup) {
    $setup($this);

    expect(sendPublicServerReport($this->tree['users']['psg'], $this->psg, 'public_server')->public_server_check)->toBe(StaffReport::PRESENCE_NO_DATA);
})->with([
    'no public server set up' => [fn ($t) => publicServers($t, [])],
    'no server could be read' => [fn ($t) => publicServers($t, [publicServer($t, 'one', null)])],
    'one could not be read, under an hour on the other' => [fn ($t) => publicServers($t, [publicServer($t, 'one', null), publicServer($t, 'two', [onServer('76561190000000020', 30)])])],
    'no credentials set' => [fn ($t) => publicServers($t, [publicServer($t, 'one', [onServer('76561190000000020', 90)], configured: false)])],
    'the source knows only who was seen' => [fn ($t) => publicServers($t, [publicServer($t, 'one', [onServer('76561190000000020', null)])])],
    'no linked game identity' => [function ($t) {
        TrooperGameIdentity::query()->delete();
        publicServers($t, [publicServer($t, 'one', [onServer('76561190000000020', 90)])]);
    }],
]);

it('confirms an hour even when another public server could not be read', function () {
    publicServers($this, [publicServer($this, 'one', null), publicServer($this, 'two', [onServer('76561190000000020', 75)])]);

    expect(sendPublicServerReport($this->tree['users']['psg'], $this->psg, 'public_server')->public_server_check)->toBe(StaffReport::PRESENCE_CONFIRMED);
});

it('falls back to a server\'s next source when the first cannot answer', function () {
    $down = new CaptureSource('crcon', 'crcon:1', fn () => throw new CaptureSourceUnavailable('crcon: timeout'));
    $twin = new CaptureSource('battlemetrics', 'battlemetrics:1', fn ($start, $end) => new CrconPresence([onServer('76561190000000020', 61)], $start, $end, CarbonImmutable::now()->toDateTimeImmutable()));
    app()->instance(CaptureSources::class, new CaptureSources([], null, null, fn () => [new CaptureServer(null, 'one', [$down, $twin])]));

    expect(sendPublicServerReport($this->tree['users']['psg'], $this->psg, 'public_server')->public_server_check)->toBe(StaffReport::PRESENCE_CONFIRMED);
});

it('never checks an office hour or "No"', function (string $presence, array $more) {
    publicServers($this, [publicServer($this, 'one', [onServer('76561190000000020', 90)])]);

    expect(sendPublicServerReport($this->tree['users']['psg'], $this->psg, $presence, $more)->public_server_check)->toBeNull()
        ->and($this->reads)->toBe([]);
})->with([
    'office hour' => ['office_hour', ['office_hour_date' => '2026-10-06', 'office_hour_note' => 'Q and A']],
    'no' => ['none', []],
]);

it('recomputes the check on an edit', function () {
    publicServers($this, [publicServer($this, 'one', [onServer('76561190000000020', 20)])]);
    sendPublicServerReport($this->tree['users']['psg'], $this->psg, 'public_server');
    publicServers($this, [publicServer($this, 'one', [onServer('76561190000000020', 80)])]);

    $edited = app(SendStaffReportHandler::class)->handle($this->tree['users']['psg'], new SendStaffReport(
        $this->psg->id, CarbonImmutable::parse('2026-10-03', 'UTC'), true, [$this->host->id], reason: 'Played more', presenceType: 'public_server',
    ));

    expect($edited->public_server_check)->toBe(StaffReport::PRESENCE_CONFIRMED)
        ->and($edited->revisions[0]['public_server_check'])->toBe(StaffReport::PRESENCE_CONFLICT);
});

it('shows the frozen check to readers on the page, the overview and the unit report', function () {
    publicServers($this, [publicServer($this, 'one', [onServer('76561190000000020', 10)])]);
    sendPublicServerReport($this->tree['users']['psg'], $this->psg, 'public_server');
    $conflict = ['check' => 'conflict', 'source' => 'public server'];

    $this->actingAs($this->tree['users']['co'])->get(route('staff-report.show', ['trooper' => $this->psg->id, 'week' => '2026-10-03']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('report.presenceType', 'public_server')->where('report.publicServer', $conflict));

    $this->actingAs($this->tree['users']['co'])->get('/staff-reports')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('groups.0.rows', fn ($rows) => collect($rows)->firstWhere('trooperId', $this->psg->id)['publicServer'] === $conflict));

    $this->actingAs($this->tree['users']['co'])->get(route('reports.show', ['unit' => $this->tree['units']['platoon']->id, 'week' => '2026-10-03']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('staffReports', fn ($rows) => collect($rows)->firstWhere('trooperId', $this->psg->id)['publicServer'] === $conflict));
});

it('lists a game\'s selected public servers only, leaving out one that is another\'s fallback', function () {
    $hll = game('hll')->id;
    $twin = GameServer::factory()->create(['game_id' => $hll, 'type' => GameServerType::Public, 'selected' => true]);
    $public = GameServer::factory()->create(['game_id' => $hll, 'type' => GameServerType::Public, 'selected' => true, 'fallback_server_id' => $twin->id]);
    GameServer::factory()->create(['game_id' => $hll, 'type' => GameServerType::Public, 'selected' => false]);
    GameServer::factory()->trainingFor($hll)->create();
    GameServer::factory()->create(['game_id' => game('arma')->id, 'type' => GameServerType::Public, 'selected' => true]);

    $listed = app(GameServerRepository::class)->publicServersFor(game('hll'));

    expect($listed->pluck('id')->all())->toBe([$public->id])
        ->and($listed->first()->fallback->id)->toBe($twin->id);
});
