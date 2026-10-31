<?php

namespace App\Integration\Crcon;

use App\Integration\Crcon\Data\CrconPlayer;
use App\Integration\Crcon\Data\CrconPresence;
use App\Integration\Crcon\Requests\FetchConnectionInfoRequest;
use App\Integration\Crcon\Requests\FetchConnectionLogsRequest;
use App\Integration\Crcon\Requests\FetchPlayerProfileRequest;
use App\Integration\Crcon\Requests\FetchPlayersHistoryRequest;
use App\Integration\ListedServer;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Saloon\Http\Request;
use Throwable;

/**
 * The single door out to CRCON (AD-3). A CommandHandler (the 7.5 capture run) calls this; it
 * owns the connector and request so no HTTP client to CRCON exists anywhere else.
 *
 * **One instance per game server.** The unit runs a CRCON per server (`CRCON_SERVERS`, else the one
 * `CRCON_BASE_URL`). A server is listed as `crcon:<server number>`, and its presence comes from its own
 * instance's CONNECTED and DISCONNECTED log lines, filtered to its server number: CRCON's player sessions
 * carry no server in what the API returns, so they cannot tell the servers apart.
 *
 * **Read-only by construction** (proposal §9.4): the request classes are a player-history query,
 * a player-profile read, a connection-log query and the server's connection info. Nothing here can kick, ban, message or otherwise change the game server, so presence
 * is read and nothing is changed. The key reaches this class only via injected config (AD-23).
 *
 * **Every failure leaves through {@see CrconUnavailable}** — unreachable, timeout, HTTP error,
 * bad key, malformed body, missing config. An empty {@see CrconPresence} therefore always means
 * "CRCON answered and nobody was there", never "we could not ask".
 */
class CrconApi
{
    /** Safety valve on pagination so a server that never stops paging cannot loop forever. */
    private const MAX_PAGES = 50;

    /**
     * The id the single-instance setup stored its one server under (#389). Still read: it names the
     * `CRCON_BASE_URL` instance (else the first one), so a selection made before multi-server support keeps capturing.
     */
    public const SERVER_ID = 'crcon';

    /** Prefix of a listed server's id; the server number follows. */
    public const SERVER_ID_PREFIX = 'crcon:';

    /** How far before the window connection logs are read, to find who was already on when it opened. */
    private const LOOKBACK_HOURS = 12;

    /** @var array<string, CrconConnector> by base URL */
    private array $connectors = [];

    /**
     * Each instance's connection info, read once per run, by base URL.
     *
     * @var array<string, array{name: string, game: string|null, number: string}>
     */
    private array $connectionInfo = [];

    /**
     * Profile sessions already read in this run, by platform id; null when the profile could not be read.
     *
     * @var array<string, list<mixed>|null>
     */
    private array $profileSessions = [];

    /**
     * `?array`: `config('services.crcon')` is null if the block is missing, and a TypeError at
     * container resolution would fire before any caller's guard exists.
     *
     * @param  array{base_url?: string|null, api_key?: string|null}|null  $config
     */
    public function __construct(private readonly ?array $config = []) {}

    /**
     * Who was on the server at any point in `[$start, $end]`, one entry per platform id.
     *
     * With a `$serverId` (a stored server, #389) only that server's connection logs count. Without one it is the
     * legacy env read of the first instance's player history, which cannot tell servers apart.
     *
     * @throws CrconUnavailable when CRCON cannot be read for any reason
     */
    public function fetchPresence(DateTimeInterface $start, DateTimeInterface $end, ?string $serverId = null): CrconPresence
    {
        $windowStart = DateTimeImmutable::createFromInterface($start)->setTimezone(new DateTimeZone('UTC'));
        $windowEnd = DateTimeImmutable::createFromInterface($end)->setTimezone(new DateTimeZone('UTC'));

        if ($windowEnd < $windowStart) {
            throw CrconUnavailable::malformed('the practice window ends before it starts');
        }

        if ($serverId !== null) {
            return $this->fetchServerPresence($serverId, $windowStart, $windowEnd);
        }

        /** @var array<string, CrconPlayer> $byPlatformId */
        $byPlatformId = [];

        $seen = 0;
        $this->profileSessions = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $result = $this->fetchPage($windowStart, $page);
            $rows = $result['players'];

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw CrconUnavailable::malformed('a player entry was not an object');
                }

                $platformId = $this->platformId($row);

                if (! $this->wasPresent($row, $windowStart, $windowEnd)) {
                    continue;
                }

                $byPlatformId[$platformId] ??= new CrconPlayer($this->playerName($row, $platformId), $platformId);
            }

            $seen += count($rows);
            $total = $result['total'];

            // `total` is authoritative when CRCON supplies it: a server that caps the page size
            // below ours returns short pages that are not the last one, and stopping on those
            // would under-report attendance. Only without a total do we trust a short page.
            if ($total !== null) {
                if ($seen >= $total) {
                    return new CrconPresence(array_values($byPlatformId), $windowStart, $windowEnd, CarbonImmutable::now()->toDateTimeImmutable());
                }

                if ($rows === []) {
                    throw CrconUnavailable::malformed("the player history ended at {$seen} of {$total} entries");
                }

                continue;
            }

            if (count($rows) < FetchPlayersHistoryRequest::PAGE_SIZE) {
                return new CrconPresence(array_values($byPlatformId), $windowStart, $windowEnd, CarbonImmutable::now()->toDateTimeImmutable());
            }
        }

        // A truncated list would under-report attendance, so it is a failure, not a short answer.
        throw CrconUnavailable::malformed('the player history did not finish paging');
    }

    /**
     * One server per configured instance, as `crcon:<server number>` with the name it gives itself and its game (#389).
     * An instance that cannot be read is left out so the others still list; only when none can be read does this fail.
     *
     * @return list<ListedServer>
     *
     * @throws CrconUnavailable when no instance can be read
     */
    public function listServers(): array
    {
        $listed = [];
        $failure = null;

        foreach ($this->instances() as $instance) {
            try {
                $info = $this->connectionInfo($instance);
            } catch (CrconUnavailable $e) {
                $failure ??= $e;

                continue;
            }

            $listed[self::SERVER_ID_PREFIX.$info['number']] = new ListedServer(
                self::SERVER_ID_PREFIX.$info['number'],
                $info['name'],
                match ($info['game']) {
                    'hll' => 'hll',
                    'hllv' => 'hll-vietnam',
                    default => null,
                },
            );
        }

        if ($listed === [] && $failure !== null) {
            throw $failure;
        }

        return array_values($listed);
    }

    /**
     * Who was on one server in the window, from its CONNECTED and DISCONNECTED log lines.
     *
     * Each player's lines are paired into stays: a connect opens one, the next disconnect closes it. A disconnect with
     * no connect before it means they joined before the look-back, so the stay starts there; a connect never closed
     * is still running at the window end. Anyone with a stay that touches the window was present.
     */
    private function fetchServerPresence(string $serverId, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): CrconPresence
    {
        [$instance, $number] = $this->resolveServer($serverId);
        $lookbackStart = $windowStart->modify('-'.self::LOOKBACK_HOURS.' hours');

        /** @var array<string, array{key: string, time: DateTimeImmutable, connect: bool, id: string, name: string, seq: int}> $events */
        $events = [];
        $from = $lookbackStart;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $rows = $this->send(new FetchConnectionLogsRequest($number, $from, $windowEnd), $instance);
            $new = 0;
            $last = null;

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw CrconUnavailable::malformed('a log line was not an object');
                }

                $event = $this->connectionEvent($row, count($events));

                if ($event === null) {
                    continue;
                }

                $last = $event['time'];

                // Paging moves `from` to the last line read, so lines at that moment come again: keep each once.
                if (isset($events[$event['key']])) {
                    continue;
                }

                $events[$event['key']] = $event;
                $new++;
            }

            if (count($rows) < FetchConnectionLogsRequest::PAGE_SIZE) {
                return $this->presenceFromEvents(array_values($events), $lookbackStart, $windowStart, $windowEnd);
            }

            if ($new === 0 || $last === null) {
                // A full page of lines already seen: more lines share one moment than a page holds.
                throw CrconUnavailable::malformed('the connection log did not finish paging');
            }

            $from = $last;
        }

        throw CrconUnavailable::malformed('the connection log did not finish paging');
    }

    /**
     * @param  array<int|string, mixed>  $row
     * @return array{key: string, time: DateTimeImmutable, connect: bool, id: string, name: string, seq: int}|null
     */
    private function connectionEvent(array $row, int $seq): ?array
    {
        $type = $row['type'] ?? null;

        if ($type !== 'CONNECTED' && $type !== 'DISCONNECTED') {
            return null;
        }

        $time = $this->moment($row['event_time'] ?? null);
        $id = $row['player1_id'] ?? null;
        $id = is_int($id) ? (string) $id : $id;

        if ($time === null || ! is_string($id) || trim($id) === '') {
            // Skipping would silently under-report; a line without a time or player means the shape changed.
            throw CrconUnavailable::malformed('a connection log line carried no usable `event_time` or `player1_id`');
        }

        $name = $row['player1_name'] ?? null;
        $key = isset($row['id']) && is_scalar($row['id']) ? 'id:'.$row['id'] : $type.'|'.$time->format('U.u').'|'.$id;

        return [
            'key' => $key,
            'time' => $time,
            'connect' => $type === 'CONNECTED',
            'id' => trim($id),
            'name' => is_string($name) && trim($name) !== '' ? trim($name) : trim($id),
            'seq' => $seq,
        ];
    }

    /**
     * @param  list<array{key: string, time: DateTimeImmutable, connect: bool, id: string, name: string, seq: int}>  $events
     */
    private function presenceFromEvents(array $events, DateTimeImmutable $lookbackStart, DateTimeImmutable $start, DateTimeImmutable $end): CrconPresence
    {
        usort($events, fn (array $a, array $b) => [$a['time'], $a['seq']] <=> [$b['time'], $b['seq']]);

        /** @var array<string, DateTimeImmutable> $open */
        $open = [];
        /** @var array<string, CrconPlayer> $present */
        $present = [];
        $names = [];

        foreach ($events as $event) {
            $names[$event['id']] = $event['name'];

            if ($event['connect']) {
                // A second connect without a disconnect between them keeps the earlier one.
                $open[$event['id']] ??= $event['time'];

                continue;
            }

            $from = $open[$event['id']] ?? $lookbackStart;
            unset($open[$event['id']]);

            if ($from <= $end && $event['time'] >= $start) {
                $present[$event['id']] ??= new CrconPlayer($names[$event['id']], $event['id']);
            }
        }

        foreach ($open as $id => $from) {
            if ($from <= $end) {
                $present[$id] ??= new CrconPlayer($names[$id], $id);
            }
        }

        return new CrconPresence(array_values($present), $start, $end, CarbonImmutable::now()->toDateTimeImmutable());
    }

    /**
     * The instance and server number behind a stored server id. The legacy `crcon` id is the `CRCON_BASE_URL`
     * instance, else the first one; `crcon:<n>` is the instance that says it runs server n.
     *
     * @return array{0: array{base_url: string, api_key: string}, 1: string}
     */
    private function resolveServer(string $serverId): array
    {
        $instances = $this->instances();

        if ($serverId === self::SERVER_ID) {
            $legacy = trim((string) ($this->config['base_url'] ?? ''));
            $instance = $instances[0];

            foreach ($instances as $candidate) {
                if ($candidate['base_url'] === $legacy) {
                    $instance = $candidate;
                    break;
                }
            }

            return [$instance, $this->connectionInfo($instance)['number']];
        }

        if (! str_starts_with($serverId, self::SERVER_ID_PREFIX)) {
            throw CrconUnavailable::misconfigured("{$serverId} is not a CRCON server id");
        }

        $number = substr($serverId, strlen(self::SERVER_ID_PREFIX));
        $failure = null;

        foreach ($instances as $instance) {
            try {
                if ($this->connectionInfo($instance)['number'] === $number) {
                    return [$instance, $number];
                }
            } catch (CrconUnavailable $e) {
                $failure ??= $e;
            }
        }

        // An instance that could not be read may be the one: report that, not that the server is gone.
        throw $failure ?? CrconUnavailable::misconfigured("no instance in CRCON_SERVERS runs server {$number}");
    }

    /**
     * @param  array{base_url: string, api_key: string}  $instance
     * @return array{name: string, game: string|null, number: string}
     */
    private function connectionInfo(array $instance): array
    {
        if (isset($this->connectionInfo[$instance['base_url']])) {
            return $this->connectionInfo[$instance['base_url']];
        }

        $result = $this->send(new FetchConnectionInfoRequest, $instance);

        $name = $result['name'] ?? null;
        $name = is_array($name) ? ($name['name'] ?? null) : $name;
        $number = $result['server_number'] ?? null;
        $number = is_int($number) ? (string) $number : $number;
        $game = $result['game'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            throw CrconUnavailable::malformed('no server `name` in the connection info');
        }

        if (! is_string($number) || trim($number) === '') {
            throw CrconUnavailable::malformed('no `server_number` in the connection info');
        }

        return $this->connectionInfo[$instance['base_url']] = [
            'name' => trim($name),
            'game' => is_string($game) ? strtolower(trim($game)) : null,
            'number' => trim($number),
        ];
    }

    /**
     * The configured instances: each `CRCON_SERVERS` entry (`url` or `url|key`), else `CRCON_BASE_URL`.
     *
     * @return non-empty-list<array{base_url: string, api_key: string}>
     */
    private function instances(): array
    {
        $sharedKey = trim((string) ($this->config['api_key'] ?? ''));
        $servers = $this->config['servers'] ?? null;
        $entries = is_array($servers) ? $servers : explode(',', (string) $servers);
        $instances = [];

        foreach ($entries as $entry) {
            $parts = explode('|', (string) $entry, 2);
            $url = trim($parts[0]);

            if ($url === '') {
                continue;
            }

            $key = trim($parts[1] ?? '');
            $instances[] = ['base_url' => $url, 'api_key' => $key !== '' ? $key : $sharedKey];
        }

        if ($instances === []) {
            $instances[] = ['base_url' => trim((string) ($this->config['base_url'] ?? '')), 'api_key' => $sharedKey];
        }

        return $instances;
    }

    /**
     * @return array{players: array<int|string, mixed>, total: int|null}
     */
    private function fetchPage(DateTimeImmutable $from, int $page): array
    {
        $result = $this->send(new FetchPlayersHistoryRequest($from, $page));

        if (! isset($result['players']) || ! is_array($result['players'])) {
            throw CrconUnavailable::malformed('no `result.players` list in the body');
        }

        return [
            'players' => $result['players'],
            'total' => is_numeric($result['total'] ?? null) ? (int) $result['total'] : null,
        ];
    }

    /**
     * Send one request to an instance (the first when none is named) and return the `result` of CRCON's envelope.
     *
     * @param  array{base_url: string, api_key: string}|null  $instance
     * @return array<string, mixed>
     */
    private function send(Request $request, ?array $instance = null): array
    {
        $instance ??= $this->instances()[0];
        $apiKey = $instance['api_key'];
        $baseUrl = $instance['base_url'];

        if ($apiKey === '') {
            throw CrconUnavailable::misconfigured('CRCON_API_KEY is empty');
        }

        if ($baseUrl === '') {
            throw CrconUnavailable::misconfigured('CRCON_BASE_URL is empty');
        }

        try {
            $response = $this->connector($baseUrl, $apiKey)->send($request);
        } catch (Throwable $e) {
            // Broad on purpose: transport errors and PSR-7 URI errors for a malformed base URL
            // must both leave as the typed failure, never as a raw exception.
            throw CrconUnavailable::unreachable($e);
        }

        if ($response->failed()) {
            throw CrconUnavailable::httpError($response->status());
        }

        try {
            $payload = $response->json();
        } catch (Throwable $e) {
            // A valid JSON scalar raises a TypeError inside Saloon, not a JsonException.
            throw CrconUnavailable::malformed('the body was not JSON');
        }

        // CRCON wraps results in an envelope and signals application errors with HTTP 200.
        if (($payload['failed'] ?? false) === true || ($payload['error'] ?? null) !== null) {
            throw CrconUnavailable::malformed('CRCON reported the request failed');
        }

        $result = $payload['result'] ?? null;

        if (! is_array($result)) {
            throw CrconUnavailable::malformed('no `result` object in the body');
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function platformId(array $row): string
    {
        $id = $row['player_id'] ?? $row['steam_id_64'] ?? null;

        if (is_int($id)) {
            $id = (string) $id;
        }

        if (! is_string($id) || trim($id) === '') {
            // Skipping would silently under-report; an id-less row means the shape changed.
            throw CrconUnavailable::malformed('a player entry carried no `player_id`');
        }

        return trim($id);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function playerName(array $row, string $fallback): string
    {
        $names = $row['names'] ?? null;
        $name = is_array($names) && is_array($names[0] ?? null) ? ($names[0]['name'] ?? null) : ($row['name'] ?? null);

        return is_string($name) && trim($name) !== '' ? trim($name) : $fallback;
    }

    /**
     * Whether the player was on the server at any point in the window, judged from their
     * sessions, else from their first/last-seen span.
     *
     * The history endpoint returns an empty `sessions` list, so for a player last seen at or after
     * the window start the sessions come from their profile instead. The first/last-seen span is
     * only the fallback for a profile with no sessions or one that could not be read: one bad
     * profile must not fail the whole capture, and the span can only over-report, never miss.
     *
     * @param  array<string, mixed>  $row
     */
    private function wasPresent(array $row, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        $sessions = $row['sessions'] ?? null;

        if (! is_array($sessions) || $sessions === []) {
            $last = $this->moment($row['last_seen_timestamp_ms'] ?? null);

            // Someone last seen before the window opened cannot have been in it; skip the profile read.
            if ($last !== null && $last < $start) {
                return false;
            }

            $sessions = $this->profileSessions($this->platformId($row));
        }

        if (is_array($sessions) && $sessions !== []) {
            foreach ($sessions as $session) {
                if (! is_array($session)) {
                    throw CrconUnavailable::malformed('a session was not an object');
                }

                $from = $this->moment($session['start'] ?? null);
                // An open session (no end) is still running, so it overlaps anything after its start.
                $till = ($session['end'] ?? null) === null ? null : $this->moment($session['end']);

                if (($session['end'] ?? null) !== null && $till === null) {
                    throw CrconUnavailable::malformed('a session carried an unusable `end`');
                }

                if ($from === null) {
                    throw CrconUnavailable::malformed('a session carried no usable `start`');
                }

                if ($from <= $end && ($till === null || $till >= $start)) {
                    return true;
                }
            }

            return false;
        }

        $first = $this->moment($row['first_seen_timestamp_ms'] ?? null);
        $last = $this->moment($row['last_seen_timestamp_ms'] ?? null);

        if ($first === null || $last === null) {
            throw CrconUnavailable::malformed('a player carried neither sessions nor a first/last-seen span');
        }

        return $first <= $end && $last >= $start;
    }

    /**
     * The sessions on a player's profile, read once per run. Null when the profile could not be read
     * or carried a session without a usable start or end.
     *
     * @return list<mixed>|null
     */
    private function profileSessions(string $platformId): ?array
    {
        if (array_key_exists($platformId, $this->profileSessions)) {
            return $this->profileSessions[$platformId];
        }

        try {
            $sessions = $this->send(new FetchPlayerProfileRequest($platformId))['sessions'] ?? null;
        } catch (CrconUnavailable) {
            $sessions = null;
        }

        if (is_array($sessions)) {
            foreach ($sessions as $session) {
                // A profile we cannot read falls back to the span instead of failing the capture.
                if (! is_array($session) || $this->moment($session['start'] ?? null) === null
                    || (($session['end'] ?? null) !== null && $this->moment($session['end']) === null)) {
                    $sessions = null;
                    break;
                }
            }
        }

        return $this->profileSessions[$platformId] = is_array($sessions) ? array_values($sessions) : null;
    }

    /**
     * An ISO-8601 string (UTC when it names no zone) or epoch milliseconds.
     */
    private function moment(mixed $value): ?DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        if (is_int($value) || is_float($value)) {
            return DateTimeImmutable::createFromFormat('U.u', sprintf('%.3f', $value / 1000), $utc) ?: null;
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return new DateTimeImmutable($value, $utc);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function connector(string $baseUrl, #[\SensitiveParameter] string $apiKey): CrconConnector
    {
        return $this->connectors[$baseUrl] ??= new CrconConnector($baseUrl, $apiKey);
    }
}
