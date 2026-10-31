<?php

use App\Integration\Crcon\CrconApi;
use App\Integration\Crcon\CrconFailureReason;
use App\Integration\Crcon\CrconUnavailable;
use App\Integration\Crcon\Requests\FetchConnectionLogsRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

/**
 * One CRCON per game server: listing every instance and reading presence from the one that runs the selected server.
 * Every call is faked with Saloon's MockClient; nothing here touches a real server.
 */
function multiCrcon(array $config = []): CrconApi
{
    return new CrconApi([
        'base_url' => null,
        'servers' => 'https://one.test/, https://two.test/|own-key ,https://three.test/',
        'api_key' => 'shared-key',
        ...$config,
    ]);
}

function crconEnvelope(mixed $result): MockResponse
{
    return MockResponse::make(['result' => $result, 'failed' => false, 'error' => null]);
}

function crconInfo(string $name, string $game, int $number): MockResponse
{
    return crconEnvelope(['name' => $name, 'short_name' => 'x', 'game' => $game, 'port' => '8010', 'link' => null, 'server_number' => $number]);
}

function crconLine(int $id, string $type, string $at, string $playerId, string $name = 'Alpha'): array
{
    return ['id' => $id, 'type' => $type, 'event_time' => $at, 'player1_id' => $playerId, 'player1_name' => $name, 'server' => '2'];
}

function multiWindow(): array
{
    return [new DateTimeImmutable('2026-10-10 19:00:00', new DateTimeZone('UTC')), new DateTimeImmutable('2026-10-10 21:00:00', new DateTimeZone('UTC'))];
}

function fiveInfos(): array
{
    return [
        'one.test/api/get_connection_info' => crconInfo('WW2 public', 'hll', 1),
        'two.test/api/get_connection_info' => crconInfo('WW2 training', 'hll', 2),
        'three.test/api/get_connection_info' => crconInfo('Vietnam training', 'hllv', 5),
    ];
}

it('lists one server per instance with its number and game', function () {
    MockClient::global(fiveInfos());

    $listed = multiCrcon()->listServers();

    expect(array_map(fn ($s) => [$s->externalId, $s->name, $s->gameSlug], $listed))->toBe([
        ['crcon:1', 'WW2 public', 'hll'],
        ['crcon:2', 'WW2 training', 'hll'],
        ['crcon:5', 'Vietnam training', 'hll-vietnam'],
    ]);
});

it('sends the shared key, or an instance its own', function () {
    $keys = [];
    MockClient::global(['*' => function (PendingRequest $request) use (&$keys) {
        $keys[$request->getUri()->getHost()] = $request->headers()->get('Authorization');

        return crconInfo('x', 'hll', crc32($request->getUri()->getHost()) % 100);
    }]);

    multiCrcon()->listServers();

    expect($keys)->toBe(['one.test' => 'Bearer shared-key', 'two.test' => 'Bearer own-key', 'three.test' => 'Bearer shared-key']);
});

it('still lists the other servers when one instance is down', function () {
    MockClient::global(['two.test/*' => MockResponse::make('down', 502), ...fiveInfos()]);

    expect(array_map(fn ($s) => $s->externalId, multiCrcon()->listServers()))->toBe(['crcon:1', 'crcon:5']);
});

it('fails the listing only when no instance answers', function () {
    MockClient::global(['*' => MockResponse::make('down', 503)]);

    expect(fn () => multiCrcon()->listServers())->toThrow(fn (CrconUnavailable $e) => expect($e->reason)->toBe(CrconFailureReason::HttpError));
});

it('treats a lone CRCON_BASE_URL as the one instance', function () {
    MockClient::global(['legacy.test/api/get_connection_info' => crconInfo('7Cav HLL', 'hll', 1)]);

    $api = new CrconApi(['base_url' => 'https://legacy.test', 'api_key' => 'k']);

    expect(array_map(fn ($s) => $s->externalId, $api->listServers()))->toBe(['crcon:1']);
});

it('reads presence from the instance running the selected server, filtered to its number', function () {
    $body = null;
    MockClient::global([
        ...fiveInfos(),
        'two.test/api/get_historical_logs' => function (PendingRequest $request) use (&$body) {
            $body = $request->body()->all();

            return crconEnvelope([
                crconLine(1, 'CONNECTED', '2026-10-10T19:10:00', '76561', 'Alpha'),
                crconLine(2, 'DISCONNECTED', '2026-10-10T20:00:00', '76561', 'Alpha'),
            ]);
        },
        'one.test/api/get_historical_logs' => fn () => throw new LogicException('read the wrong server'),
    ]);

    [$start, $end] = multiWindow();
    $presence = multiCrcon()->fetchPresence($start, $end, 'crcon:2');

    expect(array_map(fn ($p) => [$p->platformId, $p->playerName], $presence->players))->toBe([['76561', 'Alpha']])
        ->and($body)->toMatchArray(['server_filter' => '2', 'action' => 'CONNECTED', 'exact_action' => false, 'time_sort' => 'asc', 'till' => '2026-10-10T21:00:00', 'from' => '2026-10-10T07:00:00']);
});

it('pairs connects and disconnects into stays that must touch the window', function () {
    MockClient::global([
        ...fiveInfos(),
        'two.test/api/get_historical_logs' => crconEnvelope([
            // Left before the window opened.
            crconLine(1, 'CONNECTED', '2026-10-10T17:00:00', 'gone'),
            crconLine(2, 'DISCONNECTED', '2026-10-10T18:59:00', 'gone'),
            // Joined before the look-back, left during the window.
            crconLine(3, 'DISCONNECTED', '2026-10-10T19:30:00', 'early'),
            // Joined before the window and never left.
            crconLine(4, 'CONNECTED', '2026-10-10T18:00:00', 'stayed'),
            // Joined during the window, reconnected twice.
            crconLine(5, 'CONNECTED', '2026-10-10T20:00:00', 'rejoin'),
            crconLine(6, 'DISCONNECTED', '2026-10-10T20:10:00', 'rejoin'),
            crconLine(7, 'CONNECTED', '2026-10-10T20:20:00', 'rejoin'),
            // Joined at the very end.
            crconLine(8, 'CONNECTED', '2026-10-10T21:00:00', 'late'),
            // Another action is ignored.
            ['id' => 9, 'type' => 'KILL', 'event_time' => '2026-10-10T20:00:00', 'player1_id' => 'killer'],
        ]),
    ]);

    [$start, $end] = multiWindow();
    $ids = array_map(fn ($p) => $p->platformId, multiCrcon()->fetchPresence($start, $end, 'crcon:2')->players);

    sort($ids);
    expect($ids)->toBe(['early', 'late', 'rejoin', 'stayed']);
});

it('pages through a full log by moving the start to the last line, keeping each line once', function () {
    $calls = 0;
    MockClient::global([
        ...fiveInfos(),
        'two.test/api/get_historical_logs' => function () use (&$calls) {
            $calls++;
            $lines = [];

            if ($calls === 1) {
                for ($i = 0; $i < FetchConnectionLogsRequest::PAGE_SIZE; $i++) {
                    $lines[] = crconLine($i, 'CONNECTED', '2026-10-10T19:10:00', "p{$i}");
                }

                return crconEnvelope($lines);
            }

            return crconEnvelope([crconLine(999, 'CONNECTED', '2026-10-10T19:10:00', 'p999'), crconLine(5000, 'CONNECTED', '2026-10-10T19:20:00', 'last')]);
        },
    ]);

    [$start, $end] = multiWindow();

    expect(multiCrcon()->fetchPresence($start, $end, 'crcon:2')->players)->toHaveCount(FetchConnectionLogsRequest::PAGE_SIZE + 1)
        ->and($calls)->toBe(2);
});

it('keeps a server stored before multi-server support reading the CRCON_BASE_URL instance', function () {
    MockClient::global([
        ...fiveInfos(),
        'three.test/api/get_historical_logs' => crconEnvelope([crconLine(1, 'CONNECTED', '2026-10-10T19:10:00', 'vn')]),
    ]);

    [$start, $end] = multiWindow();
    $presence = multiCrcon(['base_url' => 'https://three.test/'])->fetchPresence($start, $end, CrconApi::SERVER_ID);

    expect($presence->players[0]->platformId)->toBe('vn');
});

it('reads a selected server even when another instance is down', function () {
    MockClient::global([
        'one.test/*' => MockResponse::make('down', 502),
        ...fiveInfos(),
        'two.test/api/get_historical_logs' => crconEnvelope([crconLine(1, 'CONNECTED', '2026-10-10T19:10:00', 'a')]),
    ]);

    [$start, $end] = multiWindow();

    expect(multiCrcon()->fetchPresence($start, $end, 'crcon:2')->players)->toHaveCount(1);
});

it('fails as unreachable when the instance that may run the server is down', function () {
    MockClient::global(['two.test/*' => MockResponse::make('down', 502), ...fiveInfos()]);
    [$start, $end] = multiWindow();

    expect(fn () => multiCrcon()->fetchPresence($start, $end, 'crcon:2'))->toThrow(fn (CrconUnavailable $e) => expect($e->reason)->toBe(CrconFailureReason::HttpError))
        ->and(fn () => multiCrcon()->fetchPresence($start, $end, 'crcon:4'))->toThrow(fn (CrconUnavailable $e) => expect($e->reason)->toBe(CrconFailureReason::HttpError));
});

it('fails as misconfigured when no instance runs the server', function () {
    MockClient::global(fiveInfos());
    [$start, $end] = multiWindow();

    expect(fn () => multiCrcon()->fetchPresence($start, $end, 'crcon:4'))->toThrow(fn (CrconUnavailable $e) => expect($e->reason)->toBe(CrconFailureReason::Misconfigured));
});

it('refuses a log line without a time or player', function (array $line) {
    MockClient::global([...fiveInfos(), 'two.test/api/get_historical_logs' => crconEnvelope([$line])]);
    [$start, $end] = multiWindow();

    expect(fn () => multiCrcon()->fetchPresence($start, $end, 'crcon:2'))->toThrow(fn (CrconUnavailable $e) => expect($e->reason)->toBe(CrconFailureReason::Malformed));
})->with([
    'no time' => [['type' => 'CONNECTED', 'player1_id' => 'a']],
    'no player' => [['type' => 'DISCONNECTED', 'event_time' => '2026-10-10T19:10:00']],
]);
