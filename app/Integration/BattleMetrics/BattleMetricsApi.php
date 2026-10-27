<?php

namespace App\Integration\BattleMetrics;

use App\Integration\BattleMetrics\Requests\FetchServerSessionsRequest;
use App\Integration\BattleMetrics\Requests\ListRconServersRequest;
use App\Integration\Crcon\Data\CrconPlayer;
use App\Integration\Crcon\Data\CrconPresence;
use App\Integration\ListedServer;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Saloon\Http\Request;
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
 * **Read-only by construction** (proposal §9.4): the request classes are a session-history GET and
 * the listing of the key's own servers (#389). The key reaches this class only via injected config (AD-23).
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
     * Who was on the server at any point in `[$start, $end]`, one entry per platform id. `$serverId` names the server
     * (one an Admin selected, #389); omitted, it is the env's `BATTLEMETRICS_SERVER_ID`.
     *
     * @throws BattleMetricsUnavailable when BattleMetrics cannot be read for any reason
     */
    public function fetchPresence(DateTimeInterface $start, DateTimeInterface $end, ?string $serverId = null): CrconPresence
    {
        $windowStart = DateTimeImmutable::createFromInterface($start)->setTimezone(new DateTimeZone('UTC'));
        $windowEnd = DateTimeImmutable::createFromInterface($end)->setTimezone(new DateTimeZone('UTC'));

        if ($windowEnd < $windowStart) {
            throw BattleMetricsUnavailable::malformed('the practice window ends before it starts');
        }

        [$baseUrl, $apiKey] = $this->settings();
        $serverId = $serverId === null ? $this->configuredServerId() : trim($serverId);

        if ($serverId === '') {
            throw BattleMetricsUnavailable::misconfigured('the server id is empty');
        }

        $baseHost = parse_url($baseUrl, PHP_URL_HOST);

        // Resolved only after paging: a player's identifier may arrive on a different page than
        // their session, and resolving early would split one player across two ids.
        /** @var array<string, string> $names player id => first name seen */
        $names = [];
        /** @var array<string, array<string, string>> $identifiers */
        $identifiers = [];
        $nextQuery = null;
        $complete = false;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $payload = $this->fetchPage($baseUrl, $apiKey, new FetchServerSessionsRequest($serverId, $windowStart, $windowEnd, $nextQuery));

            foreach ($this->identifiersByPlayer($payload['included'] ?? []) as $playerId => $found) {
                $identifiers[$playerId] = [...($identifiers[$playerId] ?? []), ...$found];
            }

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

                $name = $attributes['name'] ?? null;
                $names[(string) $playerId] ??= is_string($name) ? trim($name) : '';
            }

            $nextQuery = $this->nextQuery($payload, $baseHost, $nextQuery);

            if ($nextQuery === null) {
                $complete = true;
                break;
            }
        }

        // A truncated list would under-report attendance, so it is a failure, not a short answer.
        if (! $complete) {
            throw BattleMetricsUnavailable::malformed('the session history did not finish paging');
        }

        /** @var array<string, CrconPlayer> $byPlatformId */
        $byPlatformId = [];

        foreach ($names as $playerId => $name) {
            $platformId = $this->platformId($identifiers[$playerId] ?? [], (string) $playerId);
            $byPlatformId[$platformId] ??= new CrconPlayer($name !== '' ? $name : $platformId, $platformId);
        }

        return new CrconPresence(array_values($byPlatformId), $windowStart, $windowEnd, CarbonImmutable::now()->toDateTimeImmutable());
    }

    /**
     * The servers the API key has RCON access to, which are the regiment's own (#389), by id and name.
     *
     * @return list<ListedServer>
     *
     * @throws BattleMetricsUnavailable when BattleMetrics cannot be read for any reason
     */
    public function listServers(): array
    {
        [$baseUrl, $apiKey] = $this->settings();
        $baseHost = parse_url($baseUrl, PHP_URL_HOST);

        /** @var array<string, ListedServer> $byId */
        $byId = [];
        $nextQuery = null;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $payload = $this->fetchPage($baseUrl, $apiKey, new ListRconServersRequest($nextQuery));

            foreach ($payload['data'] as $server) {
                $id = is_array($server) ? ($server['id'] ?? null) : null;
                $name = is_array($server) ? ($server['attributes']['name'] ?? null) : null;

                if ((! is_string($id) && ! is_int($id)) || trim((string) $id) === '') {
                    throw BattleMetricsUnavailable::malformed('a server carried no id');
                }

                $id = trim((string) $id);
                $byId[$id] ??= new ListedServer($id, is_string($name) && trim($name) !== '' ? trim($name) : "BattleMetrics server {$id}");
            }

            $nextQuery = $this->nextQuery($payload, $baseHost, $nextQuery);

            if ($nextQuery === null) {
                return array_values($byId);
            }
        }

        // A short listing would hide a server from the Admin, so it is a failure, not a short answer.
        throw BattleMetricsUnavailable::malformed('the server listing did not finish paging');
    }

    /**
     * The query of the payload's `links.next`, or null on the last page.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>|null
     */
    private function nextQuery(array $payload, mixed $baseHost, ?array $previous): ?array
    {
        $next = $payload['links']['next'] ?? null;

        if ($next === null || $next === '') {
            return null;
        }

        // The key rides every request, so never follow a pagination link off the API host.
        if (! is_string($next) || parse_url($next, PHP_URL_HOST) !== $baseHost) {
            throw BattleMetricsUnavailable::malformed('the next-page link left the BattleMetrics host');
        }

        parse_str((string) parse_url($next, PHP_URL_QUERY), $query);

        if ($query === $previous) {
            throw BattleMetricsUnavailable::malformed('the next-page link did not advance');
        }

        return $query;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function settings(): array
    {
        $apiKey = $this->config['api_key'] ?? null;

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw BattleMetricsUnavailable::misconfigured('BATTLEMETRICS_API_KEY is empty');
        }

        $baseUrl = $this->config['base_url'] ?? null;

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            throw BattleMetricsUnavailable::misconfigured('BATTLEMETRICS_BASE_URL is empty');
        }

        return [trim($baseUrl), $apiKey];
    }

    private function configuredServerId(): string
    {
        $serverId = $this->config['server_id'] ?? null;

        if (! is_string($serverId) || trim($serverId) === '') {
            throw BattleMetricsUnavailable::misconfigured('BATTLEMETRICS_SERVER_ID is empty');
        }

        return trim($serverId);
    }

    /**
     * @return array{data: array<int|string, mixed>, included?: mixed, links?: array<string, mixed>}
     */
    private function fetchPage(string $baseUrl, #[\SensitiveParameter] string $apiKey, Request $request): array
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
        // BattleMetrics emits ISO-8601; DateTimeImmutable alone would also accept "now" or "2024".
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', trim($value))) {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private function connector(string $baseUrl, #[\SensitiveParameter] string $apiKey): BattleMetricsConnector
    {
        return $this->connector ??= new BattleMetricsConnector($baseUrl, $apiKey);
    }
}
