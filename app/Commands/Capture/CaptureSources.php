<?php

namespace App\Commands\Capture;

use App\Integration\BattleMetrics\BattleMetricsApi;
use App\Integration\BattleMetrics\BattleMetricsUnavailable;
use App\Integration\Crcon\CrconApi;
use App\Integration\Crcon\CrconUnavailable;
use App\Models\GamePlatform;
use DateTimeImmutable;

/**
 * Which sources a platform's practice is captured from, in order of preference: the first that
 * answers wins, the rest are the fallbacks. Adding a source (Nightwatch 7.10, DCS 7.11) is one
 * more entry in this map — the handler does not change.
 */
final class CaptureSources
{
    /**
     * @param  array<string, list<CaptureSource>>  $byPlatform  keyed by {@see GamePlatform} value
     */
    public function __construct(private readonly array $byPlatform) {}

    /**
     * The production map: HLL reads CRCON and falls back to BattleMetrics; Arma reads BattleMetrics.
     *
     * @param  array<string, mixed>|null  $crconConfig
     * @param  array<string, mixed>|null  $battleMetricsConfig
     */
    public static function default(CrconApi $crcon, BattleMetricsApi $battleMetrics, ?array $crconConfig, ?array $battleMetricsConfig): self
    {
        $crconSource = new CaptureSource(
            'crcon',
            (string) (parse_url((string) ($crconConfig['base_url'] ?? ''), PHP_URL_HOST) ?: 'crcon'),
            function (DateTimeImmutable $start, DateTimeImmutable $end) use ($crcon) {
                try {
                    return $crcon->fetchPresence($start, $end);
                } catch (CrconUnavailable $e) {
                    throw new CaptureSourceUnavailable("crcon: {$e->reason->value}", previous: $e);
                }
            },
        );

        $battleMetricsSource = new CaptureSource(
            'battlemetrics',
            'battlemetrics:'.(string) ($battleMetricsConfig['server_id'] ?? ''),
            function (DateTimeImmutable $start, DateTimeImmutable $end) use ($battleMetrics) {
                try {
                    return $battleMetrics->fetchPresence($start, $end);
                } catch (BattleMetricsUnavailable $e) {
                    throw new CaptureSourceUnavailable("battlemetrics: {$e->reason->value}", previous: $e);
                }
            },
        );

        return new self([
            GamePlatform::Hll->value => [$crconSource, $battleMetricsSource],
            GamePlatform::Arma->value => [$battleMetricsSource],
        ]);
    }

    /**
     * @return list<CaptureSource>
     */
    public function for(GamePlatform $platform): array
    {
        return $this->byPlatform[$platform->value] ?? [];
    }
}
