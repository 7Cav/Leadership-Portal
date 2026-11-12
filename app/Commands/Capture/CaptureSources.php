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
 *   to BattleMetrics, BattleMetrics, or Nightwatch. An install keeps its capture when it upgrades. Each env server is
 *   one game's ({@see self::LEGACY_GAMES}), so only that game reads it, and only while it keeps the interface it had: a
 *   game an Admin added or switched to another interface reads nothing until a training server is selected for it,
 *   never the server another game is played on.
 * - A game read by no interface (`none`) is not captured, whatever servers it has.
 *
 * - A staff member's public server hour reads the game's **selected public servers** ({@see publicFor()}), the same
 *   way, and never the training ones or the env server: it is not a practice capture.
 *
 * Adding a source (DCS 7.11) is one more interface, one more seam call here, and one more legacy entry — the handler
 * does not change.
 */
final class CaptureSources
{
    /**
     * The game each interface's env server is played on, by slug: the three games the install had before servers could
     * be selected (#388), with the interface each had. HLL's BattleMetrics fallback is part of its CRCON entry.
     */
    public const LEGACY_GAMES = [
        'crcon' => 'hll',
        'battlemetrics' => 'arma',
        'nightwatch' => 'wardogs',
    ];

    /**
     * @param  array<string, list<CaptureSource>>  $byInterface  the legacy env server's sources, keyed by {@see TelemetryInterface} value
     * @param  (Closure(Game): (list<CaptureServer>|null))|null  $selected  a game's selected training servers, or null when it has no selected server
     * @param  array<string, string>|null  $legacyGames  which game reads each interface's env server (interface value => slug); null lets any game read it
     * @param  (Closure(Game): list<CaptureServer>)|null  $public  a game's selected public servers
     */
    public function __construct(
        private readonly array $byInterface,
        private readonly ?Closure $selected = null,
        private readonly ?array $legacyGames = null,
        private readonly ?Closure $public = null,
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
        $source = function (TelemetryInterface $interface, ?string $serverId, ?int $gameServerId, bool $configured, bool $noServer = false) use ($crcon, $battleMetrics, $nightwatch, $crconConfig, $battleMetricsConfig, $nightwatchConfig): CaptureSource {
            return match ($interface) {
                TelemetryInterface::Crcon => new CaptureSource(
                    'crcon',
                    (string) (parse_url((string) ($crconConfig['base_url'] ?? ''), PHP_URL_HOST) ?: 'crcon'),
                    function (DateTimeImmutable $start, DateTimeImmutable $end) use ($crcon, $serverId) {
                        try {
                            return $crcon->fetchPresence($start, $end, $serverId);
                        } catch (CrconUnavailable $e) {
                            throw new CaptureSourceUnavailable(self::reason('crcon', $e->reason->value, $e->status), previous: $e);
                        }
                    },
                    $configured,
                    $gameServerId,
                    $noServer,
                ),
                TelemetryInterface::BattleMetrics => new CaptureSource(
                    'battlemetrics',
                    'battlemetrics:'.($serverId ?? (string) ($battleMetricsConfig['server_id'] ?? '')),
                    function (DateTimeImmutable $start, DateTimeImmutable $end) use ($battleMetrics, $serverId) {
                        try {
                            return $battleMetrics->fetchPresence($start, $end, $serverId);
                        } catch (BattleMetricsUnavailable $e) {
                            throw new CaptureSourceUnavailable(self::reason('battlemetrics', $e->reason->value, $e->status), previous: $e);
                        }
                    },
                    $configured,
                    $gameServerId,
                    $noServer,
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
                            throw new CaptureSourceUnavailable(self::reason('nightwatch', $e->reason->value, $e->status), previous: $e);
                        } catch (NightwatchWindowExpired $e) {
                            throw new CaptureSourceUnavailable("nightwatch: window expired ({$e->retentionHours}h retention)", previous: $e);
                        } catch (NightwatchRequestRefused $e) {
                            throw new CaptureSourceUnavailable('nightwatch: '.NightwatchFailureReason::Misconfigured->value, previous: $e);
                        }
                    },
                    $configured,
                    $gameServerId,
                    $noServer,
                ),
                TelemetryInterface::None, TelemetryInterface::Impossible => throw new \LogicException("No source reads the `{$interface->value}` interface."),
            };
        };

        $crconSource = $source(TelemetryInterface::Crcon, null, null, self::filled($crconConfig, 'base_url', 'api_key'));
        // The env BattleMetrics and Nightwatch servers also need their server id(s): with the key set and no id, no server
        // is selected, which is not the same as missing credentials (#631).
        $battleMetricsSource = $source(TelemetryInterface::BattleMetrics, null, null, self::filled($battleMetricsConfig, 'base_url', 'api_key', 'server_id'), self::filled($battleMetricsConfig, 'base_url', 'api_key') && ! self::filled($battleMetricsConfig, 'server_id'));
        $nightwatchSource = $source(TelemetryInterface::Nightwatch, null, null, self::filled($nightwatchConfig, 'base_url', 'api_key', 'server_ids'), self::filled($nightwatchConfig, 'base_url', 'api_key') && ! self::filled($nightwatchConfig, 'server_ids'));

        // An exhaustive `match`, so a new interface case without its sources fails here (and in CaptureSourcesTest)
        // instead of reading as "not configured" on every rollcall of a game that uses it.
        $byInterface = [];
        foreach (TelemetryInterface::cases() as $interface) {
            $byInterface[$interface->value] = match ($interface) {
                TelemetryInterface::Crcon => [$crconSource, $battleMetricsSource],
                TelemetryInterface::BattleMetrics => [$battleMetricsSource],
                TelemetryInterface::Nightwatch => [$nightwatchSource],
                TelemetryInterface::None, TelemetryInterface::Impossible => [],
            };
        }

        $serverSource = fn (GameServer $server) => $source($server->interface, $server->external_id, $server->id, $credentials->areSet($server->interface));
        $captureServer = fn (GameServer $server) => new CaptureServer(
            $server->id,
            $server->name,
            [$serverSource($server), ...($server->fallback === null ? [] : [$serverSource($server->fallback)])],
        );

        return new self($byInterface, function (Game $game) use ($servers, $captureServer): ?array {
            if (! $servers->hasSelectedFor($game)) {
                return null;
            }

            return $servers->trainingServersFor($game)->map($captureServer)->values()->all();
        }, self::LEGACY_GAMES, fn (Game $game): array => $servers->publicServersFor($game)->map($captureServer)->values()->all());
    }

    /**
     * The game's selected public servers, each with its sources in order: where a staff member's public server hour
     * is read. Empty for a game read by no interface, or with no public server selected.
     *
     * @return list<CaptureServer>
     */
    public function publicFor(Game $game): array
    {
        if (! $game->interface->reads() || $this->public === null) {
            return [];
        }

        return ($this->public)($game);
    }

    /**
     * The failure a source is recorded with: the typed reason, and for an HTTP error its status and what to check
     * (#632). Built only from the reason and the status, so no key, address or response body can reach it.
     */
    public static function reason(string $source, string $reason, ?int $status): string
    {
        if ($status === null) {
            return "{$source}: {$reason}";
        }

        $hint = match ($status) {
            401, 403 => ' The key was rejected.',
            404 => ' Check the server ID or address.',
            default => '',
        };

        return "{$source}: {$reason} (HTTP {$status}).{$hint}";
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
        if (! $game->interface->reads()) {
            return [];
        }

        $selected = $this->selected === null ? null : ($this->selected)($game);

        if ($selected !== null) {
            return $selected;
        }

        if (! $this->ownsEnvServer($game)) {
            return [];
        }

        $legacy = $this->byInterface[$game->interface->value] ?? [];

        return $legacy === [] ? [] : [new CaptureServer(null, "{$game->name} server", $legacy)];
    }

    /**
     * Whether a capture of the game reads its interface's env server: it has no selected server, and the env server is
     * its own ({@see self::LEGACY_GAMES}). False for a game read by no interface.
     */
    public function readsEnvServer(Game $game): bool
    {
        return $game->interface->reads()
            && $this->ownsEnvServer($game)
            && ($this->selected === null || ($this->selected)($game) === null);
    }

    private function ownsEnvServer(Game $game): bool
    {
        return $this->legacyGames === null || ($this->legacyGames[$game->interface->value] ?? null) === $game->slug;
    }
}
