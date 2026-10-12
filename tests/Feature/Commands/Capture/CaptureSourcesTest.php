<?php

use App\Commands\Capture\CaptureSources;
use App\Commands\Capture\CaptureSourceUnavailable;
use App\Integration\BattleMetrics\BattleMetricsApi;
use App\Integration\Crcon\CrconApi;
use App\Models\GamePlatform;

// 7.5: the platform -> ordered sources map. Unconfigured seams fail typed, without any HTTP.

function defaultSources(): CaptureSources
{
    $crcon = ['base_url' => 'https://crcon.example.test/path', 'api_key' => null];
    $bm = ['server_id' => '4242', 'api_key' => null];

    return CaptureSources::default(new CrconApi($crcon), new BattleMetricsApi($bm), $crcon, $bm);
}

it('reads CRCON first for HLL with BattleMetrics as fallback, and BattleMetrics for Arma', function () {
    $sources = defaultSources();

    expect(array_map(fn ($s) => $s->name, $sources->for(GamePlatform::Hll)))->toBe(['crcon', 'battlemetrics'])
        ->and(array_map(fn ($s) => $s->name, $sources->for(GamePlatform::Arma)))->toBe(['battlemetrics'])
        ->and($sources->for(GamePlatform::Hll)[0]->serverRef)->toBe('crcon.example.test')
        ->and($sources->for(GamePlatform::Hll)[1]->serverRef)->toBe('battlemetrics:4242');
});

it('translates each seam\'s typed failure into the source-agnostic one', function () {
    foreach (defaultSources()->for(GamePlatform::Hll) as $source) {
        $now = new DateTimeImmutable;

        expect(fn () => ($source->fetch)($now, $now))->toThrow(CaptureSourceUnavailable::class, "{$source->name}: misconfigured");
    }
});
