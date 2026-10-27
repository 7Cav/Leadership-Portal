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
use App\Models\GameServer;
use App\Models\TelemetryInterface;
use App\Repositories\GameServerRepository;
use Closure;
use DateTimeImmutable;

/**
 * Which game servers a game's practice is captured from (#389), each with the sources that can answer for it.
 *
 * - A game an Admin has selected servers for reads **only its selected training servers**, each by its own
 *   interface, a CRCON one falling back to its BattleMetrics twin. Public servers are never read: they would credit
 *   troopers who were only playing publicly.
 * - A game with no selected server at all reads the **legacy env server** of its interface (#388): CRCON falling back
 *   to BattleMetrics, BattleMetrics, or Nightwatch. An install keeps its capture when it upgrades.
 * - A game read by no interface (`none`) is not captured, whatever servers it has.
 *
 * Adding a source (DCS 7.11) is one more interface, one more seam call here, and one more legacy entry — the handler
 * does not change.
 */
final class CaptureSources
{
    /**
     * @param  array<string, list<CaptureSource>>  $byInterface  the legacy env server's sources, keyed by {@see TelemetryInterface} value
     * @param  (Closure(Game): (list<CaptureServer>|null))|null  $selected  a game's selected training servers, or null when it has no selected server
     */
    public function __construct(
        private readonly array $byInterface,
        private readonly ?Closure $selected = null,
    ) {}

    /**
     * The production sources: the legacy env servers, and the selected servers stored by an Admin.
     *
     * @param  array<string, mixed>|null  $crconConfig
     * @param  array<string, mixed>|null  $battleMetricsConfig
     * @param  array<string, mixed>|null  $nightwatchConfig
     */
    public static function default(CrconApi $crcon, BattleMetricsApi $battleMetrics, ?array $crconConfig, ?array $battleMetricsConfig, NightwatchApi $nightwatch, ?array $nightwatchConfig, GameServerRepository $servers, TelemetryCredentials $credentials): self
    {
        $source = function (TelemetryInterface $interface, ?string $serverId, ?int $gameServerId, bool $configured) use ($crcon, $battleMetrics, $nightwatch, $crconConfig, $battleMetricsConfig, $nightwatchConfig): CaptureSource {
            return match ($interface) {
                TelemetryInterface::Crcon => new CaptureSource(
                    'crcon',
                    (string) (parse_url((string) ($crconConfig['base_url'] ?? ''), PHP_URL_HOST) ?: 'crcon'),
                    function (DateTimeImmutable $start, DateTimeImmutable $end) use ($crcon) {
                        try {
                            return $crcon->fetchPresence($start, $end);
                        } catch (CrconUnavailable $e) {
                            throw new CaptureSourceUnavailable("crcon: {$e->reason->value}", previous: $e);
                        }
                    },
                    $configured,
                    $gameServerId,
                ),
                TelemetryInterface::BattleMetrics => new CaptureSource(
                    'battlemetrics',
                    'battlemetrics:'.($serverId ?? (string) ($battleMetricsConfig['server_id'] ?? '')),
                    function (DateTimeImmutable $start, DateTimeImmutable $end) use ($battleMetrics, $serverId) {
                        try {
                            return $battleMetrics->fetchPresence($start, $end, $serverId);
                        } catch (BattleMetricsUnavailable $e) {
                            throw new CaptureSourceUnavailable("battlemetrics: {$e->reason->value}", previous: $e);
                        }
                    },
                    $configured,
                    $gameServerId,
                ),
                // A window past Nightwatch's retention is as unanswerable as an outage: same typed failure. A refused
                // request can only come from a NIGHTWATCH_BASE_URL outside /api/public/stats here, a deployment fault
                // that no retry fixes, so it is recorded as a misconfigured source instead of failing the job.
                TelemetryInterface::Nightwatch => new CaptureSource(
                    'nightwatch',
                    'nightwatch:'.($serverId ?? (string) ($nightwatchConfig['server_ids'] ?? '')),
                    function (DateTimeImmutable $start, DateTimeImmutable $end) use ($nightwatch, $serverId) {
                        try {
                            return $nightwatch->fetchPresence($start, $end, $serverId);
                        } catch (NightwatchUnavailable $e) {
                            throw new CaptureSourceUnavailable("nightwatch: {$e->reason->value}", previous: $e);
                        } catch (NightwatchWindowExpired $e) {
                            throw new CaptureSourceUnavailable("nightwatch: window expired ({$e->retentionHours}h retention)", previous: $e);
                        } catch (NightwatchRequestRefused $e) {
                            throw new CaptureSourceUnavailable('nightwatch: '.NightwatchFailureReason::Misconfigured->value, previous: $e);
                        }
                    },
                    $configured,
                    $gameServerId,
                ),
                TelemetryInterface::None => throw new \LogicException('No source reads the `none` interface.'),
            };
        };

        $crconSource = $source(TelemetryInterface::Crcon, null, null, self::filled($crconConfig, 'base_url', 'api_key'));
        $battleMetricsSource = $source(TelemetryInterface::BattleMetrics, null, null, self::filled($battleMetricsConfig, 'base_url', 'api_key', 'server_id'));
        $nightwatchSource = $source(TelemetryInterface::Nightwatch, null, null, self::filled($nightwatchConfig, 'base_url', 'api_key', 'server_ids'));

        // An exhaustive `match`, so a new interface case without its sources fails here (and in CaptureSourcesTest)
        // instead of reading as "not configured" on every rollcall of a game that uses it.
        $byInterface = [];
        foreach (TelemetryInterface::cases() as $interface) {
            $byInterface[$interface->value] = match ($interface) {
                TelemetryInterface::Crcon => [$crconSource, $battleMetricsSource],
                TelemetryInterface::BattleMetrics => [$battleMetricsSource],
                TelemetryInterface::Nightwatch => [$nightwatchSource],
                TelemetryInterface::None => [],
            };
        }

        $serverSource = fn (GameServer $server) => $source($server->interface, $server->external_id, $server->id, $credentials->areSet($server->interface));

        return new self($byInterface, function (Game $game) use ($servers, $serverSource): ?array {
            if (! $servers->hasSelectedFor($game)) {
                return null;
            }

            return $servers->trainingServersFor($game)->map(fn (GameServer $server) => new CaptureServer(
                $server->id,
                $server->name,
                [$serverSource($server), ...($server->fallback === null ? [] : [$serverSource($server->fallback)])],
            ))->values()->all();
        });
    }

    /** True when every named key is set: the legacy env server's check, which also needs its server id(s). */
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
     * Whether any server of the game has a source with its credentials set (AD-23). False means a capture would not
     * be attempted at all: no selected training server, no credentials, or a game read by no interface.
     */
    public function isConfigured(Game $game): bool
    {
        foreach ($this->for($game) as $server) {
            if ($server->configuredSources() !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The servers a capture of the game reads, in order. Every one must answer.
     *
     * @return list<CaptureServer>
     */
    public function for(Game $game): array
    {
        if ($game->interface === TelemetryInterface::None) {
            return [];
        }

        $selected = $this->selected === null ? null : ($this->selected)($game);

        if ($selected !== null) {
            return $selected;
        }

        $legacy = $this->byInterface[$game->interface->value] ?? [];

        return $legacy === [] ? [] : [new CaptureServer(null, "{$game->name} server", $legacy)];
    }
}
