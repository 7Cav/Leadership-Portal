<?php

use App\Commands\Capture\CaptureSectionPractice;
use App\Commands\Capture\CaptureSectionPracticeHandler;
use App\Commands\Capture\CaptureServer;
use App\Commands\Capture\CaptureSource;
use App\Commands\Capture\CaptureSources;
use App\Commands\Capture\CaptureSourceUnavailable;
use App\Integration\Crcon\Data\CrconPresence;
use App\Models\AuditLogEntry;
use App\Models\GameServer;
use App\Models\SectionPractice;
use App\Models\TelemetryConnection;
use App\Models\TelemetryInterface;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

// #183: the Game server connections page lists every game, its interface, whether it is configured and how each of its
// sources last went, as recorded by the capture runs and Test connection. Never a credential or an address (AD-23).

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 20:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();
});

function answering(string $interface, ?array &$asked = null): CaptureSource
{
    return new CaptureSource($interface, "{$interface}-ref", function ($start, $end) use (&$asked) {
        $asked = [$start, $end];

        return new CrconPresence([], $start, $end, CarbonImmutable::now()->toDateTimeImmutable());
    });
}

function down(string $interface): CaptureSource
{
    return new CaptureSource($interface, "{$interface}-ref", fn () => throw new CaptureSourceUnavailable("{$interface}: unreachable"));
}

/** Stands in for the source map: the env server of each interface, and the selected servers of a game, by slug. */
function connectionSources(array $byInterface, array $selectedBySlug = []): void
{
    app()->instance(CaptureSources::class, new CaptureSources(
        $byInterface,
        fn ($game) => $selectedBySlug[$game->slug] ?? null,
        CaptureSources::LEGACY_GAMES,
    ));
}

it('lists every game with its interface, configured state and the last outcome of each source, for configured, unconfigured, down and none', function () {
    $training = GameServer::factory()->trainingFor(game('arma')->id)->create(['name' => 'Arma Training', 'interface' => TelemetryInterface::BattleMetrics]);
    $unset = new CaptureSource('nightwatch', 'nightwatch-ref', fn () => throw new LogicException('not read'), configured: false);
    connectionSources(
        ['crcon' => [answering('crcon')], 'battlemetrics' => [], 'nightwatch' => [$unset], 'none' => []],
        ['arma' => [new CaptureServer($training->id, 'Arma Training', [new CaptureSource('battlemetrics', 'bm', fn () => null, gameServerId: $training->id)])]],
    );
    TelemetryConnection::query()->create(['game_id' => game('hll')->id, 'target' => 'env:crcon', 'interface' => 'crcon', 'last_succeeded_at' => now()->subHour()]);
    TelemetryConnection::query()->create(['game_id' => game('arma')->id, 'target' => "server:{$training->id}:battlemetrics", 'game_server_id' => $training->id, 'interface' => 'battlemetrics', 'last_succeeded_at' => now()->subDay(), 'last_failed_at' => now()->subMinute(), 'last_failure_reason' => 'battlemetrics: http_error']);

    $this->actingAs($this->admin)->get('/settings/connections')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Settings/Connections')
        ->has('games', 4)
        // Configured: HLL reads the CRCON server set in env, which answered an hour ago.
        ->where('games.0.interface', 'crcon')
        ->where('games.0.configured', true)
        ->where('games.0.servers', [['label' => 'Hell Let Loose (WW2) server', 'sources' => [
            ['interface' => 'crcon', 'configured' => true, 'lastSucceededAt' => '2026-10-08T19:00:00+00:00', 'lastFailedAt' => null, 'lastFailureReason' => null],
        ]]])
        // Down: Arma's training server failed a minute ago, with its typed reason.
        ->where('games.1.servers.0.label', 'Arma Training')
        ->where('games.1.servers.0.sources.0.lastFailedAt', '2026-10-08T19:59:00+00:00')
        ->where('games.1.servers.0.sources.0.lastFailureReason', 'battlemetrics: http_error')
        // Unconfigured: WARDOGS' Nightwatch credentials are not set.
        ->where('games.2.configured', false)
        ->where('games.2.servers.0.sources.0.configured', false)
        ->where('games.2.servers.0.sources.0.lastSucceededAt', null)
        // None: Vietnam is read by no interface and has nothing to read.
        ->where('games.3.interface', 'none')
        ->where('games.3.configured', false)
        ->where('games.3.servers', [])
        ->where('testResult', null)
    );
});

it('never sends a credential or an address', function () {
    config()->set('services.crcon', ['base_url' => 'https://crcon-secret-host.test', 'api_key' => 'secret-key-123']);
    app()->forgetInstance(CaptureSources::class);

    $html = $this->actingAs($this->admin)->get('/settings/connections')->assertOk()->getContent();

    expect($html)->not->toContain('crcon-secret-host')->not->toContain('secret-key-123');
});

it('records each capture run\'s outcome per source, keeping the last success beside the last failure', function () {
    $section = Unit::factory()->create(['type' => UnitType::Section]);
    $practice = SectionPractice::factory()->create(['section_id' => $section->id, 'week_start' => '2026-10-03 00:00:00']);
    $capture = fn () => app(CaptureSectionPracticeHandler::class)->handle(new CaptureSectionPractice(
        $practice->id, game('hll')->id, CarbonImmutable::parse('2026-10-08 18:00', 'UTC')->toDateTimeImmutable(), CarbonImmutable::parse('2026-10-08 19:00', 'UTC')->toDateTimeImmutable(), 'system',
    ));

    connectionSources(['crcon' => [answering('crcon')]]);
    $capture();
    Carbon::setTestNow(now()->addMinutes(30));
    connectionSources(['crcon' => [down('crcon'), down('battlemetrics')]]);
    $capture();

    $crcon = TelemetryConnection::query()->where('target', 'env:crcon')->sole();
    expect($crcon->game_id)->toBe(game('hll')->id)
        ->and($crcon->last_succeeded_at->toIso8601String())->toBe('2026-10-08T20:00:00+00:00')
        ->and($crcon->last_failed_at->toIso8601String())->toBe('2026-10-08T20:30:00+00:00')
        ->and($crcon->last_failure_reason)->toBe('crcon: unreachable')
        ->and(TelemetryConnection::query()->where('target', 'env:battlemetrics')->sole()->last_succeeded_at)->toBeNull();
});

it('tests a connection with a read-only call through the sources and records the outcome, without auditing', function () {
    $asked = null;
    $unset = new CaptureSource('battlemetrics', 'bm', fn () => throw new LogicException('not read'), configured: false);
    connectionSources(['crcon' => [answering('crcon', $asked), $unset]]);

    $this->actingAs($this->admin)->post('/settings/connections/'.game('hll')->id.'/test')
        ->assertRedirect('/settings/connections')
        ->assertSessionHas('connection_test', ['gameId' => game('hll')->id, 'outcomes' => [
            ['server' => 'Hell Let Loose (WW2) server', 'interface' => 'crcon', 'status' => 'ok'],
            ['server' => 'Hell Let Loose (WW2) server', 'interface' => 'battlemetrics', 'status' => 'not_configured'],
        ]]);

    expect($asked[1]->getTimestamp() - $asked[0]->getTimestamp())->toBe(600)
        ->and(TelemetryConnection::query()->where('target', 'env:crcon')->sole()->last_succeeded_at->toIso8601String())->toBe('2026-10-08T20:00:00+00:00')
        ->and(TelemetryConnection::query()->where('target', 'env:battlemetrics')->exists())->toBeFalse()
        ->and(AuditLogEntry::query()->count())->toBe(0);
});

it('reports a source that is down with its typed reason', function () {
    connectionSources(['crcon' => [down('crcon')]]);

    $this->actingAs($this->admin)->post('/settings/connections/'.game('hll')->id.'/test')
        ->assertSessionHas('connection_test.outcomes', [['server' => 'Hell Let Loose (WW2) server', 'interface' => 'crcon', 'status' => 'failed', 'reason' => 'crcon: unreachable']]);

    expect(TelemetryConnection::query()->sole()->last_failure_reason)->toBe('crcon: unreachable');
});

it('tests nothing for a game read by none', function () {
    connectionSources(['crcon' => [answering('crcon')]]);

    $this->actingAs($this->admin)->post('/settings/connections/'.game('hll-vietnam')->id.'/test')
        ->assertSessionHas('connection_test.outcomes', []);

    expect(TelemetryConnection::query()->count())->toBe(0);
});

it('refuses a non-Admin the page and the test, before looking the game up', function () {
    $leader = User::factory()->serving()->create();

    $this->actingAs($leader)->get('/settings/connections')->assertForbidden();
    $this->actingAs($leader)->post('/settings/connections/999999/test')->assertForbidden();
    $this->actingAs($this->admin)->post('/settings/connections/999999/test')->assertNotFound();
});
