<?php

namespace App\Commands\Capture;

use App\Integration\BattleMetrics\BattleMetricsApi;
use App\Integration\BattleMetrics\BattleMetricsUnavailable;
use App\Integration\Crcon\CrconApi;
use App\Integration\Crcon\CrconUnavailable;
use App\Integration\Nightwatch\NightwatchApi;
use App\Integration\Nightwatch\NightwatchFailureReason;
use App\Integration\Nightwatch\NightwatchRequestRefused;
use App\Integration\Nightwatch\NightwatchUnavailable;
use App\Integration\Nightwatch\NightwatchWindowExpired;
use App\Models\Game;
use App\Models\TelemetryInterface;
use DateTimeImmutable;

/**
 * Which sources a game's practice is captured from, in order of preference: the first that
 * answers wins, the rest are the fallbacks. Keyed by telemetry interface, not by game (#388): a
 * game follows whichever interface an Admin picked for it, so a new game needs no entry here.
 * Adding a source (DCS 7.11) is one more interface and one more entry in this map — the handler
 * does not change.
 */
final class CaptureSources
{
    /**
     * @param  array<string, list<CaptureSource>>  $byInterface  keyed by {@see TelemetryInterface} value
     */
    public function __construct(private readonly array $byInterface) {}

    /**
     * The production map: CRCON falls back to BattleMetrics (HLL's servers are on both); BattleMetrics
     * and Nightwatch read only themselves; `none` has no source.
     *
     * @param  array<string, mixed>|null  $crconConfig
     * @param  array<string, mixed>|null  $battleMetricsConfig
     * @param  array<string, mixed>|null  $nightwatchConfig
     */
    public static function default(CrconApi $crcon, BattleMetricsApi $battleMetrics, ?array $crconConfig, ?array $battleMetricsConfig, NightwatchApi $nightwatch, ?array $nightwatchConfig): self
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
            self::filled($crconConfig, 'base_url', 'api_key'),
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
            self::filled($battleMetricsConfig, 'base_url', 'api_key', 'server_id'),
        );

        // A window past Nightwatch's retention is as unanswerable as an outage: same typed failure. A refused request
        // can only come from a NIGHTWATCH_BASE_URL outside /api/public/stats here, a deployment fault that no retry
        // fixes, so it is recorded as a misconfigured source instead of failing the job.
        $nightwatchSource = new CaptureSource(
            'nightwatch',
            'nightwatch:'.(string) ($nightwatchConfig['server_ids'] ?? ''),
            function (DateTimeImmutable $start, DateTimeImmutable $end) use ($nightwatch) {
                try {
                    return $nightwatch->fetchPresence($start, $end);
                } catch (NightwatchUnavailable $e) {
                    throw new CaptureSourceUnavailable("nightwatch: {$e->reason->value}", previous: $e);
                } catch (NightwatchWindowExpired $e) {
                    throw new CaptureSourceUnavailable("nightwatch: window expired ({$e->retentionHours}h retention)", previous: $e);
                } catch (NightwatchRequestRefused $e) {
                    throw new CaptureSourceUnavailable('nightwatch: '.NightwatchFailureReason::Misconfigured->value, previous: $e);
                }
            },
            self::filled($nightwatchConfig, 'base_url', 'api_key', 'server_ids'),
        );

        return new self([
            TelemetryInterface::Crcon->value => [$crconSource, $battleMetricsSource],
            TelemetryInterface::BattleMetrics->value => [$battleMetricsSource],
            TelemetryInterface::Nightwatch->value => [$nightwatchSource],
            TelemetryInterface::None->value => [],
        ]);
    }

    /** True when every named key is set — the same test each seam applies before it will call out. */
    private static function filled(?array $config, string ...$keys): bool
    {
        foreach ($keys as $key) {
            if (blank($config[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether any source of the game has its keys set (AD-23). False means a capture would not
     * be attempted at all. A game with no source at all (interface `none`) is not configured either.
     */
    public function isConfigured(Game $game): bool
    {
        return array_filter($this->for($game), fn (CaptureSource $source) => $source->configured) !== [];
    }

    /**
     * @return list<CaptureSource>
     */
    public function for(Game $game): array
    {
        return $this->byInterface[$game->interface->value] ?? [];
    }
}
