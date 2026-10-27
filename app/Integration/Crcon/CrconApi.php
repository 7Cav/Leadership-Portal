<?php

namespace App\Integration\Crcon;

use App\Integration\Crcon\Data\CrconPlayer;
use App\Integration\Crcon\Data\CrconPresence;
use App\Integration\Crcon\Requests\FetchPlayersHistoryRequest;
use App\Integration\Crcon\Requests\FetchPublicInfoRequest;
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
 * **Read-only by construction** (proposal §9.4): the request classes are a player-history query
 * and the server's public info. Nothing here can kick, ban, message or otherwise change the game server, so presence
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

    /** The id a CRCON instance's one server is stored under (#389). */
    public const SERVER_ID = 'crcon';

    private ?CrconConnector $connector = null;

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
     * @throws CrconUnavailable when CRCON cannot be read for any reason
     */
    public function fetchPresence(DateTimeInterface $start, DateTimeInterface $end): CrconPresence
    {
        $windowStart = DateTimeImmutable::createFromInterface($start)->setTimezone(new DateTimeZone('UTC'));
        $windowEnd = DateTimeImmutable::createFromInterface($end)->setTimezone(new DateTimeZone('UTC'));

        if ($windowEnd < $windowStart) {
            throw CrconUnavailable::malformed('the practice window ends before it starts');
        }

        /** @var array<string, CrconPlayer> $byPlatformId */
        $byPlatformId = [];

        $seen = 0;

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
     * The one server this CRCON instance runs, by the name it gives itself (#389). Its id is always
     * {@see self::SERVER_ID}: an instance is one server, and the URL that tells instances apart is a credential (AD-23).
     *
     * @return list<ListedServer>
     *
     * @throws CrconUnavailable when CRCON cannot be read for any reason
     */
    public function listServers(): array
    {
        $result = $this->send(new FetchPublicInfoRequest);

        // Newer CRCON versions nest the name (`name.name`); older ones give it as a string.
        $name = is_array($result['name'] ?? null) ? ($result['name']['name'] ?? null) : ($result['name'] ?? null);

        if (! is_string($name) || trim($name) === '') {
            throw CrconUnavailable::malformed('no server `name` in the public info');
        }

        return [new ListedServer(self::SERVER_ID, trim($name))];
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
     * Send one request and return the `result` of CRCON's envelope.
     *
     * @return array<string, mixed>
     */
    private function send(Request $request): array
    {
        $apiKey = $this->config['api_key'] ?? null;

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw CrconUnavailable::misconfigured('CRCON_API_KEY is empty');
        }

        $baseUrl = $this->config['base_url'] ?? null;

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            throw CrconUnavailable::misconfigured('CRCON_BASE_URL is empty');
        }

        try {
            $response = $this->connector(trim($baseUrl), $apiKey)->send($request);
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
     * sessions when CRCON supplies them, else from their first/last-seen span.
     *
     * @param  array<string, mixed>  $row
     */
    private function wasPresent(array $row, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        $sessions = $row['sessions'] ?? null;

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
        return $this->connector ??= new CrconConnector($baseUrl, $apiKey);
    }
}
