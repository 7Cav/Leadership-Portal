<?php

use App\Commands\Capture\CaptureServer;
use App\Commands\Capture\CaptureSources;
use App\Commands\Capture\CaptureSourceUnavailable;
use App\Commands\Capture\TelemetryCredentials;
use App\Integration\BattleMetrics\BattleMetricsApi;
use App\Integration\Crcon\CrconApi;
use App\Integration\Nightwatch\NightwatchApi;
use App\Models\Game;
use App\Models\GameServer;
use App\Models\GameServerType;
use App\Models\TelemetryInterface;
use App\Repositories\GameServerRepository;

// 7.5 / #388 / #389: the servers a game's practice is read from, each with its sources in order. Unconfigured seams
// fail typed, without any HTTP.

function defaultSources(?array $crcon = null, ?array $bm = null, ?array $nw = null): CaptureSources
{
    $crcon ??= ['base_url' => 'https://crcon.example.test/path', 'api_key' => null];
    $bm ??= ['base_url' => 'https://bm.example.test', 'server_id' => '4242', 'api_key' => null];
    $nw ??= ['base_url' => 'https://nightwatch.example.test/api/public/stats', 'api_key' => null, 'server_ids' => 'srv-a'];

    return CaptureSources::default(
        new CrconApi($crcon), new BattleMetricsApi($bm), $crcon, $bm, new NightwatchApi($nw), $nw,
        app(GameServerRepository::class),
        new TelemetryCredentials(['crcon' => $crcon, 'battlemetrics' => $bm, 'nightwatch' => $nw]),
    );
}

/** @param list<CaptureServer> $servers */
function sourceNames(array $servers): array
{
    return array_map(fn (CaptureServer $server) => array_map(fn ($s) => $s->name, $server->sources), $servers);
}

describe('with no server selected for the game (the legacy env server)', function () {
    it('reads CRCON first for HLL with BattleMetrics as fallback, and BattleMetrics for Arma', function () {
        $sources = defaultSources();
        $hll = $sources->for(game('hll'));

        expect(sourceNames($hll))->toBe([['crcon', 'battlemetrics']])
            ->and($hll[0]->gameServerId)->toBeNull()
            ->and(sourceNames($sources->for(game('arma'))))->toBe([['battlemetrics']])
            ->and($hll[0]->sources[0]->serverRef)->toBe('crcon.example.test')
            ->and($hll[0]->sources[1]->serverRef)->toBe('battlemetrics:4242')
            ->and(sourceNames($sources->for(game('wardogs'))))->toBe([['nightwatch']])
            ->and($sources->for(game('wardogs'))[0]->sources[0]->serverRef)->toBe('nightwatch:srv-a');
    });

    it('follows the interface an Admin picked for a game, not the game itself', function () {
        $sources = defaultSources();

        expect(sourceNames($sources->for(Game::factory()->create(['interface' => TelemetryInterface::Nightwatch]))))->toBe([['nightwatch']])
            ->and(sourceNames($sources->for(Game::factory()->create(['interface' => TelemetryInterface::Crcon]))))->toBe([['crcon', 'battlemetrics']]);

        $hll = game('hll');
        $hll->interface = TelemetryInterface::BattleMetrics;

        expect(sourceNames($sources->for($hll)))->toBe([['battlemetrics']]);
    });

    it('translates each seam\'s typed failure into the source-agnostic one', function () {
        foreach ([...defaultSources()->for(game('hll'))[0]->sources, ...defaultSources()->for(game('wardogs'))[0]->sources] as $source) {
            $now = new DateTimeImmutable;

            expect(fn () => ($source->fetch)($now, $now))->toThrow(CaptureSourceUnavailable::class, "{$source->name}: misconfigured");
        }
    });

    it('is not configured while none of its interface\'s keys are set', function () {
        expect(defaultSources()->isConfigured(game('hll')))->toBeFalse()
            ->and(defaultSources(crcon: ['base_url' => 'https://crcon.test', 'api_key' => 'k'])->isConfigured(game('hll')))->toBeTrue();
    });
});

it('has no server, and so is not configured, for a game read by no interface, even with servers selected', function () {
    $none = Game::factory()->create(['interface' => TelemetryInterface::None]);
    GameServer::factory()->trainingFor($none->id)->create();

    expect(defaultSources()->for($none))->toBe([])
        ->and(defaultSources()->isConfigured($none))->toBeFalse();
});

describe('with servers selected for the game', function () {
    it('reads only its selected training servers, each by its own interface, a CRCON one with its BattleMetrics twin', function () {
        $twin = GameServer::factory()->create(['external_id' => '777', 'selected' => true]);
        $crcon = GameServer::factory()->trainingFor(game('hll')->id)->create(['interface' => TelemetryInterface::Crcon, 'external_id' => 'crcon', 'fallback_server_id' => $twin->id]);
        $bm = GameServer::factory()->trainingFor(game('hll')->id)->create(['external_id' => '888']);
        GameServer::factory()->create(['game_id' => game('hll')->id, 'selected' => true, 'type' => GameServerType::Public, 'external_id' => '999']);
        GameServer::factory()->create(['game_id' => game('hll')->id, 'selected' => false, 'type' => GameServerType::Training, 'external_id' => '555']);

        $servers = defaultSources()->for(game('hll'));

        expect(array_map(fn (CaptureServer $s) => $s->gameServerId, $servers))->toBe([$crcon->id, $bm->id])
            ->and(sourceNames($servers))->toBe([['crcon', 'battlemetrics'], ['battlemetrics']])
            ->and($servers[0]->sources[1]->serverRef)->toBe('battlemetrics:777')
            ->and($servers[0]->sources[1]->gameServerId)->toBe($twin->id)
            ->and($servers[1]->sources[0]->serverRef)->toBe('battlemetrics:888');
    });

    it('stops reading the legacy env server once any server is selected, even a public one', function () {
        GameServer::factory()->create(['game_id' => game('arma')->id, 'selected' => true, 'type' => GameServerType::Public]);
        $sources = defaultSources(bm: ['base_url' => 'https://bm.test', 'api_key' => 'k', 'server_id' => '4242']);

        expect($sources->for(game('arma')))->toBe([])
            ->and($sources->isConfigured(game('arma')))->toBeFalse();
    });

    it('is configured when the interface of one training server has its credentials, and marks the others unconfigured', function () {
        GameServer::factory()->trainingFor(game('arma')->id)->create();
        GameServer::factory()->trainingFor(game('arma')->id)->create(['interface' => TelemetryInterface::Nightwatch]);

        $sources = defaultSources(bm: ['base_url' => 'https://bm.test', 'api_key' => 'k']);
        $servers = $sources->for(game('arma'));

        expect($sources->isConfigured(game('arma')))->toBeTrue()
            ->and($servers[0]->configuredSources())->toHaveCount(1)
            ->and($servers[1]->configuredSources())->toBe([]);
    });

    it('needs no BATTLEMETRICS_SERVER_ID: a selected server reads its own id', function () {
        GameServer::factory()->trainingFor(game('arma')->id)->create(['external_id' => '31337']);

        $source = defaultSources(bm: ['base_url' => 'https://bm.test', 'api_key' => 'k'])->for(game('arma'))[0]->sources[0];

        expect($source->configured)->toBeTrue()->and($source->serverRef)->toBe('battlemetrics:31337');
    });
});

it('records a Nightwatch base URL outside /api/public/stats as misconfigured, so the job does not retry it', function () {
    $nw = ['base_url' => 'https://nightwatch.example.test/api/v2', 'api_key' => 'NWKEY-0123456789-REST', 'server_ids' => 'srv-a'];
    $sources = CaptureSources::default(new CrconApi([]), new BattleMetricsApi([]), [], [], new NightwatchApi($nw), $nw);
    $now = new DateTimeImmutable;

    expect(fn () => ($sources->for(game('wardogs'))[0]->sources[0]->fetch)($now->modify('-1 hour'), $now))
        ->toThrow(CaptureSourceUnavailable::class, 'nightwatch: misconfigured');
});
