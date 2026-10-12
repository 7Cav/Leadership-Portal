<?php

namespace App\Integration\BattleMetrics;

use App\Integration\BattleMetrics\Requests\FetchServerSessionsRequest;
use App\Integration\Crcon\Data\CrconPlayer;
use App\Integration\Crcon\Data\CrconPresence;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * The single door out to BattleMetrics (AD-3). A CommandHandler (the 7.5 capture run) calls
 * this; it owns the connector and request so no HTTP client to BattleMetrics exists anywhere
 * else. The Arma Reforger practice source, and the fallback for HLL when CRCON is down.
 *
 * Returns the **same presence DTO as the CRCON seam** ({@see CrconPresence}, built from
 * {@see CrconPlayer}) so the capture run treats both sources uniformly with no per-source
 * branch. Which source an HLL practice uses is the orchestrator's choice, not this seam's.
 *
 * **Read-only by construction** (proposal §9.4): the only request class is a session-history
 * GET. The key reaches this class only via injected config (AD-23).
 *
 * **Every failure leaves through {@see BattleMetricsUnavailable}** — unreachable, timeout, HTTP
 * error, bad key, malformed body, missing config. An empty presence therefore always means
 * "BattleMetrics answered and nobody was there", never "we could not ask".
 */
class BattleMetricsApi
{
    /** Safety valve on pagination so an upstream that never stops paging cannot loop forever. */
    private const MAX_PAGES = 50;

    /**
     * Identifier types, in order of preference, that make a stable platform id. Anything else
     * falls back to the BattleMetrics player id, which is stable but BattleMetrics-specific.
     */
    private const PLATFORM_ID_TYPES = ['reforgerUUID', 'steamID', 'playFabID'];

    private ?BattleMetricsConnector $connector = null;

    /**
     * `?array`: `config('services.battlemetrics')` is null if the block is missing, and a
     * TypeError at container resolution would fire before any caller's guard exists.
     *
     * @param  array{base_url?: string|null, api_key?: string|null, server_id?: string|null}|null  $config
     */
    public function __construct(private readonly ?array $config = []) {}

    /**
     * Who was on the configured server at any point in `[$start, $end]`, one entry per platform id.
     *
     * @throws BattleMetricsUnavailable when BattleMetrics cannot be read for any reason
     */
    public function fetchPresence(DateTimeInterface $start, DateTimeInterface $end): CrconPresence
    {
        $windowStart = DateTimeImmutable::createFromInterface($start)->setTimezone(new DateTimeZone('UTC'));
        $windowEnd = DateTimeImmutable::createFromInterface($end)->setTimezone(new DateTimeZone('UTC'));

        if ($windowEnd < $windowStart) {
            throw BattleMetricsUnavailable::malformed('the practice window ends before it starts');
        }

        [$baseUrl, $apiKey, $serverId] = $this->settings();
        $baseHost = parse_url($baseUrl, PHP_URL_HOST);

        /** @var array<string, CrconPlayer> $byPlatformId */
        $byPlatformId = [];
        $nextQuery = null;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $payload = $this->fetchPage($baseUrl, $apiKey, new FetchServerSessionsRequest($serverId, $windowStart, $windowEnd, $nextQuery));

            $identifiers = $this->identifiersByPlayer($payload['included'] ?? []);

            foreach ($payload['data'] as $session) {
                if (! is_array($session)) {
                    throw BattleMetricsUnavailable::malformed('a session entry was not an object');
                }

                $attributes = $session['attributes'] ?? null;

                if (! is_array($attributes)) {
                    throw BattleMetricsUnavailable::malformed('a session carried no attributes');
                }

                if (! $this->overlaps($attributes, $windowStart, $windowEnd)) {
                    continue;
                }

                $playerId = $session['relationships']['player']['data']['id'] ?? null;

                if (! is_string($playerId) && ! is_int($playerId)) {
                    throw BattleMetricsUnavailable::malformed('a session carried no player');
                }

                $playerId = (string) $playerId;
                $platformId = $this->platformId($identifiers[$playerId] ?? [], $playerId);
                $name = $attributes['name'] ?? null;
                $name = is_string($name) && trim($name) !== '' ? trim($name) : $platformId;

                $byPlatformId[$platformId] ??= new CrconPlayer($name, $platformId);
            }

            $next = $payload['links']['next'] ?? null;

            if ($next === null || $next === '') {
                return new CrconPresence(array_values($byPlatformId), $windowStart, $windowEnd, CarbonImmutable::now()->toDateTimeImmutable());
            }

            // The key rides every request, so never follow a pagination link off the API host.
            if (! is_string($next) || parse_url($next, PHP_URL_HOST) !== $baseHost) {
                throw BattleMetricsUnavailable::malformed('the next-page link left the BattleMetrics host');
            }

            parse_str((string) parse_url($next, PHP_URL_QUERY), $nextQuery);
        }

        // A truncated list would under-report attendance, so it is a failure, not a short answer.
        throw BattleMetricsUnavailable::malformed('the session history did not finish paging');
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function settings(): array
    {
        $apiKey = $this->config['api_key'] ?? null;

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw BattleMetricsUnavailable::misconfigured('BATTLEMETRICS_API_KEY is empty');
        }

        $serverId = $this->config['server_id'] ?? null;

        if (! is_string($serverId) || trim($serverId) === '') {
            throw BattleMetricsUnavailable::misconfigured('BATTLEMETRICS_SERVER_ID is empty');
        }

        $baseUrl = $this->config['base_url'] ?? null;

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            throw BattleMetricsUnavailable::misconfigured('BATTLEMETRICS_BASE_URL is empty');
        }

        return [trim($baseUrl), $apiKey, trim($serverId)];
    }

    /**
     * @return array{data: array<int|string, mixed>, included?: mixed, links?: array<string, mixed>}
     */
    private function fetchPage(string $baseUrl, string $apiKey, FetchServerSessionsRequest $request): array
    {
        try {
            $response = $this->connector($baseUrl, $apiKey)->send($request);
        } catch (Throwable $e) {
            // Broad on purpose: transport errors and PSR-7 URI errors for a malformed base URL
            // must both leave as the typed failure, never as a raw exception.
            throw BattleMetricsUnavailable::unreachable($e);
        }

        if ($response->failed()) {
            throw BattleMetricsUnavailable::httpError($response->status());
        }

        try {
            $payload = $response->json();
        } catch (Throwable $e) {
            // A valid JSON scalar raises a TypeError inside Saloon, not a JsonException.
            throw BattleMetricsUnavailable::malformed('the body was not JSON');
        }

        if (! is_array($payload) || ! isset($payload['data']) || ! is_array($payload['data'])) {
            throw BattleMetricsUnavailable::malformed('no `data` list in the body');
        }

        if (isset($payload['links']) && ! is_array($payload['links'])) {
            throw BattleMetricsUnavailable::malformed('`links` was not an object');
        }

        return $payload;
    }

    /**
     * Index the included `identifier` resources by the BattleMetrics player they belong to.
     *
     * @return array<string, array<string, string>> player id => identifier type => identifier
     */
    private function identifiersByPlayer(mixed $included): array
    {
        $byPlayer = [];

        if (! is_array($included)) {
            return $byPlayer;
        }

        foreach ($included as $resource) {
            if (! is_array($resource) || ($resource['type'] ?? null) !== 'identifier') {
                continue;
            }

            $playerId = $resource['relationships']['player']['data']['id'] ?? null;
            $type = $resource['attributes']['type'] ?? null;
            $value = $resource['attributes']['identifier'] ?? null;

            if ((is_string($playerId) || is_int($playerId)) && is_string($type) && is_string($value) && trim($value) !== '') {
                $byPlayer[(string) $playerId][$type] ??= trim($value);
            }
        }

        return $byPlayer;
    }

    /**
     * @param  array<string, string>  $identifiers  type => identifier for one player
     */
    private function platformId(array $identifiers, string $playerId): string
    {
        foreach (self::PLATFORM_ID_TYPES as $type) {
            if (isset($identifiers[$type])) {
                return $identifiers[$type];
            }
        }

        return $playerId;
    }

    /**
     * Whether a session overlapped the window. A session with no `stop` is still running, so it
     * overlaps anything after its start.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function overlaps(array $attributes, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        $from = $this->moment($attributes['start'] ?? null);

        if ($from === null) {
            throw BattleMetricsUnavailable::malformed('a session carried no usable `start`');
        }

        $till = null;

        if (($attributes['stop'] ?? null) !== null) {
            $till = $this->moment($attributes['stop']);

            if ($till === null) {
                // Treating an unparseable end as "still running" would invent attendance.
                throw BattleMetricsUnavailable::malformed('a session carried an unusable `stop`');
            }
        }

        return $from <= $end && ($till === null || $till >= $start);
    }

    private function moment(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private function connector(string $baseUrl, string $apiKey): BattleMetricsConnector
    {
        return $this->connector ??= new BattleMetricsConnector($baseUrl, $apiKey);
    }
}
