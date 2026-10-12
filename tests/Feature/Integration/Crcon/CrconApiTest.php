<?php

use App\Integration\Crcon\CrconApi;
use App\Integration\Crcon\CrconFailureReason;
use App\Integration\Crcon\CrconUnavailable;
use App\Integration\Crcon\Requests\FetchPlayersHistoryRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Http\Request;
use Saloon\Http\Response;

/**
 * Story 7.3 — the CRCON read seam. Every call is faked with Saloon's MockClient; nothing here
 * touches a real server.
 */
const CRCON_URL = 'crcon.test/api/get_players_history';

function crcon(?array $config = ['base_url' => 'https://crcon.test', 'api_key' => 'test-key']): CrconApi
{
    return new CrconApi($config);
}

function crconWindow(): array
{
    return [new DateTimeImmutable('2026-10-10 19:00:00', new DateTimeZone('UTC')), new DateTimeImmutable('2026-10-10 21:00:00', new DateTimeZone('UTC'))];
}

function crconPlayer(string $id, string $name, array $sessions): array
{
    return ['player_id' => $id, 'names' => [['name' => $name]], 'sessions' => $sessions];
}

function crconResult(array $players, array $extra = []): MockResponse
{
    return MockResponse::make(['result' => ['players' => $players, ...$extra], 'failed' => false, 'error' => null]);
}

it('returns the players present in the window with name and platform id', function () {
    MockClient::global([CRCON_URL => crconResult([
        crconPlayer('7656119000000001', 'Alpha', [['start' => '2026-10-10T19:10:00', 'end' => '2026-10-10T20:00:00']]),
    ])]);

    [$start, $end] = crconWindow();
    $presence = crcon()->fetchPresence($start, $end);

    expect($presence->players)->toHaveCount(1)
        ->and($presence->players[0]->playerName)->toBe('Alpha')
        ->and($presence->players[0]->platformId)->toBe('7656119000000001')
        ->and($presence->windowStart)->toEqual($start);
});

it('lists a player who rejoined more than once exactly once', function () {
    MockClient::global([CRCON_URL => crconResult([
        crconPlayer('1', 'Alpha', [
            ['start' => '2026-10-10T19:05:00', 'end' => '2026-10-10T19:30:00'],
            ['start' => '2026-10-10T19:40:00', 'end' => '2026-10-10T20:30:00'],
        ]),
        crconPlayer('1', 'Alpha', [['start' => '2026-10-10T20:40:00', 'end' => '2026-10-10T20:50:00']]),
    ])]);

    [$start, $end] = crconWindow();

    expect(crcon()->fetchPresence($start, $end)->players)->toHaveCount(1);
});

it('excludes players whose sessions fall wholly outside the window', function () {
    MockClient::global([CRCON_URL => crconResult([
        crconPlayer('1', 'Before', [['start' => '2026-10-10T17:00:00', 'end' => '2026-10-10T18:59:00']]),
        crconPlayer('2', 'After', [['start' => '2026-10-10T21:01:00', 'end' => '2026-10-10T22:00:00']]),
        crconPlayer('3', 'Straddler', [['start' => '2026-10-10T18:00:00', 'end' => '2026-10-10T19:01:00']]),
        crconPlayer('4', 'StillOn', [['start' => '2026-10-10T20:00:00', 'end' => null]]),
    ])]);

    [$start, $end] = crconWindow();
    $ids = array_map(fn ($p) => $p->platformId, crcon()->fetchPresence($start, $end)->players);

    expect($ids)->toBe(['3', '4']);
});

it('falls back to the first/last-seen span when no sessions are supplied', function () {
    $ms = fn (string $t) => (new DateTimeImmutable($t, new DateTimeZone('UTC')))->getTimestamp() * 1000;

    MockClient::global([CRCON_URL => crconResult([
        ['player_id' => '1', 'names' => [['name' => 'In']], 'first_seen_timestamp_ms' => $ms('2026-10-01 10:00'), 'last_seen_timestamp_ms' => $ms('2026-10-10 20:00')],
        ['player_id' => '2', 'names' => [['name' => 'Out']], 'first_seen_timestamp_ms' => $ms('2026-10-01 10:00'), 'last_seen_timestamp_ms' => $ms('2026-10-10 18:00')],
    ])]);

    [$start, $end] = crconWindow();
    $ids = array_map(fn ($p) => $p->platformId, crcon()->fetchPresence($start, $end)->players);

    expect($ids)->toBe(['1']);
});

it('returns an empty success, not a failure, when CRCON answers with nobody in the window', function () {
    MockClient::global([CRCON_URL => crconResult([])]);

    [$start, $end] = crconWindow();
    $presence = crcon()->fetchPresence($start, $end);

    expect($presence->players)->toBe([]);
});

it('pages through the history until it is complete', function () {
    $page = fn (int $from, int $count) => array_map(
        fn ($i) => crconPlayer((string) $i, "P{$i}", [['start' => '2026-10-10T19:10:00', 'end' => '2026-10-10T20:00:00']]),
        range($from, $from + $count - 1),
    );

    $mock = MockClient::global([CRCON_URL => function (PendingRequest $request) use ($page) {
        return $request->body()->all()['page'] === 1
            ? crconResult($page(1, FetchPlayersHistoryRequest::PAGE_SIZE), ['total' => FetchPlayersHistoryRequest::PAGE_SIZE + 3])
            : crconResult($page(FetchPlayersHistoryRequest::PAGE_SIZE + 1, 3), ['total' => FetchPlayersHistoryRequest::PAGE_SIZE + 3]);
    }]);

    [$start, $end] = crconWindow();

    expect(crcon()->fetchPresence($start, $end)->players)->toHaveCount(FetchPlayersHistoryRequest::PAGE_SIZE + 3);
    $mock->assertSentCount(2);
});

it('authenticates with the configured key and only ever issues the history read', function () {
    $mock = MockClient::global([CRCON_URL => crconResult([])]);

    [$start, $end] = crconWindow();
    crcon()->fetchPresence($start, $end);

    $mock->assertSent(function (Request $request, Response $response) {
        return $response->getPendingRequest()->headers()->get('Authorization') === 'Bearer test-key'
            && $request instanceof FetchPlayersHistoryRequest;
    });

    $mock->assertSentCount(1);
});

it('raises a typed failure for every way CRCON can be unavailable', function (array|string $body, int $status, string $reason) {
    MockClient::global([CRCON_URL => MockResponse::make($body, $status)]);

    [$start, $end] = crconWindow();

    try {
        crcon()->fetchPresence($start, $end);
        $this->fail('Expected a CrconUnavailable.');
    } catch (CrconUnavailable $e) {
        expect($e->reason->value)->toBe($reason);
    }
})->with([
    'server error' => ['boom', 500, 'http_error'],
    'bad key' => [['error' => 'no'], 401, 'http_error'],
    'enveloped failure' => [['result' => null, 'failed' => true, 'error' => 'x'], 200, 'malformed'],
    'not json' => ['<html>', 200, 'malformed'],
    'json scalar' => ['123', 200, 'malformed'],
    'no players list' => [['result' => ['x' => 1]], 200, 'malformed'],
    'player without id' => [['result' => ['players' => [['names' => [['name' => 'x']], 'sessions' => []]]]], 200, 'malformed'],
]);

it('raises a typed failure when the server cannot be reached', function () {
    MockClient::destroyGlobal();

    [$start, $end] = crconWindow();

    // Port 1 refuses immediately, so no network is needed.
    try {
        crcon(['base_url' => 'http://127.0.0.1:1', 'api_key' => 'k'])->fetchPresence($start, $end);
        $this->fail('Expected a CrconUnavailable.');
    } catch (CrconUnavailable $e) {
        expect($e->reason)->toBe(CrconFailureReason::Unreachable)->and($e->getPrevious())->not->toBeNull();
    }
});

it('reports missing configuration as a deployment fault without sending anything', function (?array $config) {
    $mock = MockClient::global([CRCON_URL => crconResult([])]);

    [$start, $end] = crconWindow();

    try {
        crcon($config)->fetchPresence($start, $end);
        $this->fail('Expected a CrconUnavailable.');
    } catch (CrconUnavailable $e) {
        expect($e->reason)->toBe(CrconFailureReason::Misconfigured);
    }

    $mock->assertNothingSent();
})->with([
    'no config block' => [null],
    'empty key' => [['base_url' => 'https://crcon.test', 'api_key' => '']],
    'empty url' => [['base_url' => '', 'api_key' => 'k']],
]);

it('rejects a window that ends before it starts', function () {
    [$start, $end] = crconWindow();

    expect(fn () => crcon()->fetchPresence($end, $start))->toThrow(CrconUnavailable::class);
});

it('resolves from the container with the crcon config', function () {
    config(['services.crcon' => ['base_url' => 'https://crcon.test', 'api_key' => 'test-key']]);
    MockClient::global([CRCON_URL => crconResult([])]);

    [$start, $end] = crconWindow();

    expect(app(CrconApi::class)->fetchPresence($start, $end)->players)->toBe([]);
});

it('keeps paging on short pages while the reported total is not yet reached', function () {
    $row = fn ($i) => crconPlayer((string) $i, "P{$i}", [['start' => '2026-10-10T19:10:00', 'end' => '2026-10-10T20:00:00']]);

    $mock = MockClient::global([CRCON_URL => function (PendingRequest $request) use ($row) {
        return $request->body()->all()['page'] === 1
            ? crconResult([$row(1), $row(2)], ['total' => 3])
            : crconResult([$row(3)], ['total' => 3]);
    }]);

    [$start, $end] = crconWindow();

    expect(crcon()->fetchPresence($start, $end)->players)->toHaveCount(3);
    $mock->assertSentCount(2);
});

it('fails rather than return a short list when the history ends before its total', function () {
    MockClient::global([CRCON_URL => crconResult([], ['total' => 5])]);

    [$start, $end] = crconWindow();

    expect(fn () => crcon()->fetchPresence($start, $end))->toThrow(CrconUnavailable::class);
});

it('fails on a session with an unparseable end instead of treating it as still running', function () {
    MockClient::global([CRCON_URL => crconResult([crconPlayer('1', 'A', [['start' => '2026-10-10T19:10:00', 'end' => 'garbage']])])]);

    [$start, $end] = crconWindow();

    expect(fn () => crcon()->fetchPresence($start, $end))->toThrow(CrconUnavailable::class);
});
