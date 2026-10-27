<?php

namespace App\Integration\Nightwatch;

use App\Integration\Crcon\Data\CrconPlayer;
use App\Integration\Crcon\Data\CrconPresence;
use App\Integration\ListedServer;
use App\Integration\Nightwatch\Requests\FetchLiveServersRequest;
use App\Integration\Nightwatch\Requests\FetchMatchesRequest;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Saloon\Http\Request;
use Throwable;

/**
 * The single door out to Nightwatch (AD-3), the community WARDOGS admin console the unit uses as
 * a hosted service. A CommandHandler (the 7.5 capture run) calls this; it owns the connector and
 * request so no HTTP client to Nightwatch, or to a WARDOGS server, exists anywhere else. The
 * portal never holds a WARDOGS RCON password.
 *
 * Returns the **same presence DTO as the CRCON and BattleMetrics seams** ({@see CrconPresence},
 * built from {@see CrconPlayer}) so the capture run has no WARDOGS-specific branch.
 *
 * **Presence is match-level, not exact join/leave times.** The public API exposes no join log, so
 * a player counts as present if they are on the scoreboard of any match overlapping the window
 * (`matchStart < windowEnd AND matchEnd > windowStart`; a live match ends "now"). Someone who
 * joined a match for a minute at the edge of the window counts. Matching is by SteamID64 only
 * (`platform_id`); the name is display data. `GET /servers/live` (current state) lists the
 * workspace's servers for the Servers page (#389).
 *
 * **Retention:** matches are kept 48 hours. A window that ended longer ago raises
 * {@see NightwatchWindowExpired}, never an empty list.
 *
 * **Read-only by construction** (proposal §9.4): the connector refuses anything but GET under
 * `/api/public/stats`, the key is read-only, and it reaches this class only via injected config (AD-23).
 *
 * **Every other failure leaves through {@see NightwatchUnavailable}** — unreachable, timeout,
 * 401/403/429/5xx, malformed body, missing config. An empty presence therefore always means
 * "Nightwatch answered and nobody was there".
 */
class NightwatchApi
{
    /** How far back Nightwatch keeps matches. */
    public const RETENTION_HOURS = 48;

    private ?NightwatchConnector $connector = null;

    /**
     * `?array`: `config('services.nightwatch')` is null if the block is missing, and a
     * TypeError at container resolution would fire before any caller's guard exists.
     *
     * @param  array{base_url?: string|null, api_key?: string|null, server_ids?: string|null}|null  $config
     */
    public function __construct(private readonly ?array $config = []) {}

    /**
     * Who was on the workspace's WARDOGS server(s) at any point in `[$start, $end]`, one entry per
     * SteamID64. `$serverId` reads that one server (one an Admin selected, #389); omitted, every
     * server in the env's `NIGHTWATCH_SERVER_IDS` counts.
     *
     * @throws NightwatchWindowExpired when the window ended beyond Nightwatch's retention
     * @throws NightwatchUnavailable when Nightwatch cannot be read for any other reason
     */
    public function fetchPresence(DateTimeInterface $start, DateTimeInterface $end, ?string $serverId = null): CrconPresence
    {
        $windowStart = DateTimeImmutable::createFromInterface($start)->setTimezone(new DateTimeZone('UTC'));
        $windowEnd = DateTimeImmutable::createFromInterface($end)->setTimezone(new DateTimeZone('UTC'));
        $now = CarbonImmutable::now()->toDateTimeImmutable();

        if ($windowEnd < $windowStart) {
            throw NightwatchUnavailable::malformed('the practice window ends before it starts');
        }

        [$baseUrl, $apiKey] = $this->settings();
        $serverIds = $serverId === null ? $this->configuredServerIds() : [trim($serverId)];

        if ($serverIds === ['']) {
            throw NightwatchUnavailable::misconfigured('the server id is empty');
        }

        if ($windowEnd < $now->modify('-'.self::RETENTION_HOURS.' hours')) {
            throw new NightwatchWindowExpired($windowEnd, self::RETENTION_HOURS);
        }

        /** @var array<string, CrconPlayer> $byPlatformId */
        $byPlatformId = [];

        foreach ($serverIds as $id) {
            $matches = $this->fetchMatches($baseUrl, $apiKey, $id, $windowStart);

            foreach ($matches as $match) {
                if (! is_array($match)) {
                    throw NightwatchUnavailable::malformed('a match entry was not an object');
                }

                // The request is already filtered to this server, so a different id means the
                // contract changed; skipping would turn that into a believable "nobody came".
                if (($match['serverId'] ?? null) !== $id) {
                    throw NightwatchUnavailable::malformed('a match did not belong to the requested server');
                }

                if (! $this->overlaps($match, $windowStart, $windowEnd, $now)) {
                    continue;
                }

                $scoreboard = $match['board']['scoreboard'] ?? null;

                if (! is_array($scoreboard)) {
                    throw NightwatchUnavailable::malformed('a match carried no scoreboard');
                }

                foreach ($scoreboard as $row) {
                    $steamId = is_array($row) ? ($row['steamId'] ?? null) : null;

                    if (! is_string($steamId) || trim($steamId) === '') {
                        throw NightwatchUnavailable::malformed('a scoreboard row carried no steamId');
                    }

                    $steamId = trim($steamId);
                    $name = is_string($row['name'] ?? null) ? trim($row['name']) : '';
                    $byPlatformId[$steamId] ??= new CrconPlayer($name !== '' ? $name : $steamId, $steamId);
                }
            }
        }

        return new CrconPresence(array_values($byPlatformId), $windowStart, $windowEnd, $now);
    }

    /**
     * The workspace's servers, by id and name (#389). Nightwatch documents no listing of a workspace's servers beyond
     * `/servers/live`, so its shape is read defensively: a `servers` list, or a bare list, of objects carrying an
     * `id` (or `serverId`) and a `name` (or `serverName`). Anything else is a malformed answer, never an empty list.
     *
     * @return list<ListedServer>
     *
     * @throws NightwatchUnavailable when Nightwatch cannot be read for any reason
     */
    public function listServers(): array
    {
        [$baseUrl, $apiKey] = $this->settings();
        $payload = $this->send($baseUrl, $apiKey, new FetchLiveServersRequest);
        $servers = array_is_list($payload) ? $payload : ($payload['servers'] ?? null);

        if (! is_array($servers)) {
            throw NightwatchUnavailable::malformed('no `servers` list in the body');
        }

        /** @var array<string, ListedServer> $byId */
        $byId = [];

        foreach ($servers as $server) {
            $id = is_array($server) ? ($server['id'] ?? $server['serverId'] ?? null) : null;

            if ((! is_string($id) && ! is_int($id)) || trim((string) $id) === '') {
                throw NightwatchUnavailable::malformed('a server carried no id');
            }

            $id = trim((string) $id);
            $name = $server['name'] ?? $server['serverName'] ?? null;
            $byId[$id] ??= new ListedServer($id, is_string($name) && trim($name) !== '' ? trim($name) : "Nightwatch server {$id}");
        }

        return array_values($byId);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function settings(): array
    {
        $apiKey = $this->config['api_key'] ?? null;

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw NightwatchUnavailable::misconfigured('NIGHTWATCH_API_KEY is empty');
        }

        $baseUrl = $this->config['base_url'] ?? null;

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            throw NightwatchUnavailable::misconfigured('NIGHTWATCH_BASE_URL is empty');
        }

        return [trim($baseUrl), $apiKey];
    }

    /**
     * @return list<string>
     */
    private function configuredServerIds(): array
    {
        $ids = array_values(array_filter(
            array_map('trim', explode(',', (string) ($this->config['server_ids'] ?? ''))),
            fn (string $id) => $id !== '',
        ));

        if ($ids === []) {
            throw NightwatchUnavailable::misconfigured('NIGHTWATCH_SERVER_IDS is empty');
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int|string, mixed>
     */
    private function fetchMatches(string $baseUrl, #[\SensitiveParameter] string $apiKey, string $serverId, DateTimeImmutable $windowStart): array
    {
        $payload = $this->send($baseUrl, $apiKey, new FetchMatchesRequest($serverId));

        if (! isset($payload['matches']) || ! is_array($payload['matches'])) {
            throw NightwatchUnavailable::malformed('no `matches` list in the body');
        }

        // A full page may hide older matches inside the window (the order is not documented, so
        // judge by the earliest start seen). A truncated list would under-report attendance, so it
        // is a failure, not a short answer; a full page reaching back before the window is fine.
        if (count($payload['matches']) >= FetchMatchesRequest::LIMIT) {
            $earliest = null;

            foreach ($payload['matches'] as $match) {
                $from = is_array($match) ? $this->moment($match['startedAtUtc'] ?? null) : null;
                $earliest = $from !== null && ($earliest === null || $from < $earliest) ? $from : $earliest;
            }

            if ($earliest === null || $earliest >= $windowStart) {
                throw NightwatchUnavailable::truncated();
            }
        }

        return $payload['matches'];
    }

    /**
     * Send one request and return its JSON body.
     *
     * @return array<int|string, mixed>
     */
    private function send(string $baseUrl, #[\SensitiveParameter] string $apiKey, Request $request): array
    {
        try {
            $response = $this->connector($baseUrl, $apiKey)->send($request);
        } catch (NightwatchRequestRefused $e) {
            throw $e;
        } catch (Throwable $e) {
            // Broad on purpose: transport errors and PSR-7 URI errors for a malformed base URL
            // must both leave as the typed failure, never as a raw exception.
            throw NightwatchUnavailable::unreachable($e);
        }

        if ($response->failed()) {
            throw NightwatchUnavailable::httpError($response->status());
        }

        try {
            $payload = $response->json();
        } catch (Throwable) {
            // A valid JSON scalar raises a TypeError inside Saloon, not a JsonException.
            throw NightwatchUnavailable::malformed('the body was not JSON');
        }

        if (! is_array($payload)) {
            throw NightwatchUnavailable::malformed('the body was not a JSON object or list');
        }

        return $payload;
    }

    /**
     * Whether a match overlapped the window: `matchStart < windowEnd AND matchEnd > windowStart`.
     * A live match has no settled end, so "now" stands in for it.
     *
     * @param  array<string, mixed>  $match
     */
    private function overlaps(array $match, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $now): bool
    {
        $from = $this->moment($match['startedAtUtc'] ?? null);

        if ($from === null) {
            throw NightwatchUnavailable::malformed('a match carried no usable `startedAtUtc`');
        }

        if (($match['live'] ?? false) === true) {
            $till = $now;
        } else {
            $till = $this->moment($match['endedAtUtc'] ?? null);

            if ($till === null) {
                // Treating an unparseable end as "still running" would invent attendance.
                throw NightwatchUnavailable::malformed('a match carried no usable `endedAtUtc`');
            }
        }

        return $from < $end && $till > $start;
    }

    private function moment(mixed $value): ?DateTimeImmutable
    {
        // Nightwatch emits ISO-8601; DateTimeImmutable alone would also accept "now" or "2024".
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', trim($value))) {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private function connector(string $baseUrl, #[\SensitiveParameter] string $apiKey): NightwatchConnector
    {
        return $this->connector ??= new NightwatchConnector($baseUrl, $apiKey);
    }
}
