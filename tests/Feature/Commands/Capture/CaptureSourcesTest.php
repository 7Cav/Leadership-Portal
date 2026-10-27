<?php

use App\Commands\Capture\CaptureSources;
use App\Commands\Capture\CaptureSourceUnavailable;
use App\Integration\BattleMetrics\BattleMetricsApi;
use App\Integration\Crcon\CrconApi;
use App\Integration\Nightwatch\NightwatchApi;
use App\Models\Game;
use App\Models\TelemetryInterface;

// 7.5: the ordered sources a game's practice is read from, keyed by the game's telemetry interface (#388).
// Unconfigured seams fail typed, without any HTTP.

function defaultSources(): CaptureSources
{
    $crcon = ['base_url' => 'https://crcon.example.test/path', 'api_key' => null];
    $bm = ['server_id' => '4242', 'api_key' => null];

    $nw = ['base_url' => 'https://nightwatch.example.test/api/public/stats', 'api_key' => null, 'server_ids' => 'srv-a'];

    return CaptureSources::default(new CrconApi($crcon), new BattleMetricsApi($bm), $crcon, $bm, new NightwatchApi($nw), $nw);
}

it('reads CRCON first for HLL with BattleMetrics as fallback, and BattleMetrics for Arma', function () {
    $sources = defaultSources();

    expect(array_map(fn ($s) => $s->name, $sources->for(game('hll'))))->toBe(['crcon', 'battlemetrics'])
        ->and(array_map(fn ($s) => $s->name, $sources->for(game('arma'))))->toBe(['battlemetrics'])
        ->and($sources->for(game('hll'))[0]->serverRef)->toBe('crcon.example.test')
        ->and($sources->for(game('hll'))[1]->serverRef)->toBe('battlemetrics:4242')
        ->and(array_map(fn ($s) => $s->name, $sources->for(game('wardogs'))))->toBe(['nightwatch'])
        ->and($sources->for(game('wardogs'))[0]->serverRef)->toBe('nightwatch:srv-a');
});

it('follows the interface an Admin picked for a game, not the game itself', function () {
    $sources = defaultSources();

    expect(array_map(fn ($s) => $s->name, $sources->for(Game::factory()->create(['interface' => TelemetryInterface::Nightwatch]))))->toBe(['nightwatch'])
        ->and(array_map(fn ($s) => $s->name, $sources->for(Game::factory()->create(['interface' => TelemetryInterface::Crcon]))))->toBe(['crcon', 'battlemetrics']);

    $hll = game('hll');
    $hll->interface = TelemetryInterface::BattleMetrics;

    expect(array_map(fn ($s) => $s->name, $sources->for($hll)))->toBe(['battlemetrics']);
});

it('has no source, and so is not configured, for a game read by no interface', function () {
    $none = Game::factory()->create(['interface' => TelemetryInterface::None]);

    expect(defaultSources()->for($none))->toBe([])
        ->and(defaultSources()->isConfigured($none))->toBeFalse();
});

it('translates each seam\'s typed failure into the source-agnostic one', function () {
    foreach ([...defaultSources()->for(game('hll')), ...defaultSources()->for(game('wardogs'))] as $source) {
        $now = new DateTimeImmutable;

        expect(fn () => ($source->fetch)($now, $now))->toThrow(CaptureSourceUnavailable::class, "{$source->name}: misconfigured");
    }
});

it('records a Nightwatch base URL outside /api/public/stats as misconfigured, so the job does not retry it', function () {
    $nw = ['base_url' => 'https://nightwatch.example.test/api/v2', 'api_key' => 'NWKEY-0123456789-REST', 'server_ids' => 'srv-a'];
    $sources = CaptureSources::default(new CrconApi([]), new BattleMetricsApi([]), [], [], new NightwatchApi($nw), $nw);
    $now = new DateTimeImmutable;

    expect(fn () => ($sources->for(game('wardogs'))[0]->fetch)($now->modify('-1 hour'), $now))
        ->toThrow(CaptureSourceUnavailable::class, 'nightwatch: misconfigured');
});
