<?php

use App\Integration\BattleMetrics\BattleMetricsApi;
use App\Integration\BattleMetrics\BattleMetricsFailureReason;
use App\Integration\BattleMetrics\BattleMetricsUnavailable;
use App\Integration\Crcon\Data\CrconPlayer;
use App\Integration\Crcon\Data\CrconPresence;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Http\Request;
use Saloon\Http\Response;

/**
 * Story 7.4 — the BattleMetrics read seam. Every call is faked with Saloon's MockClient; nothing
 * here touches the real API.
 */
const BM_URL = 'bm.test/servers/42/relationships/sessions*';

function bm(?array $config = ['base_url' => 'https://bm.test', 'api_key' => 'test-key', 'server_id' => '42']): BattleMetricsApi
{
    return new BattleMetricsApi($config);
}

function bmWindow(): array
{
    return [new DateTimeImmutable('2026-10-10 19:00:00', new DateTimeZone('UTC')), new DateTimeImmutable('2026-10-10 21:00:00', new DateTimeZone('UTC'))];
}

function bmSession(string $playerId, string $name, string $start, ?string $stop): array
{
    return [
        'type' => 'session',
        'id' => 's'.$playerId.$start,
        'attributes' => ['name' => $name, 'start' => $start, 'stop' => $stop],
        'relationships' => ['player' => ['data' => ['type' => 'player', 'id' => $playerId]]],
    ];
}

function bmIdentifier(string $playerId, string $type, string $value): array
{
    return [
        'type' => 'identifier',
        'id' => $type.$playerId,
        'attributes' => ['type' => $type, 'identifier' => $value],
        'relationships' => ['player' => ['data' => ['type' => 'player', 'id' => $playerId]]],
    ];
}

function bmBody(array $sessions, array $included = [], ?string $next = null): MockResponse
{
    return MockResponse::make(['data' => $sessions, 'included' => $included, 'links' => $next ? ['next' => $next] : []]);
}

it('returns the players present in the window as the shared presence DTO', function () {
    MockClient::global([BM_URL => bmBody(
        [bmSession('9', 'Alpha', '2026-10-10T19:10:00.000Z', '2026-10-10T20:00:00.000Z')],
        [bmIdentifier('9', 'reforgerUUID', 'uuid-alpha')],
    )]);

    [$start, $end] = bmWindow();
    $presence = bm()->fetchPresence($start, $end);

    expect($presence)->toBeInstanceOf(CrconPresence::class)
        ->and($presence->players)->toHaveCount(1)
        ->and($presence->players[0]->playerName)->toBe('Alpha')
        ->and($presence->players[0]->platformId)->toBe('uuid-alpha')
        ->and($presence->windowStart)->toEqual($start)
        ->and($presence->windowEnd)->toEqual($end);
});

it('prefers a game identifier and falls back to the BattleMetrics player id', function () {
    MockClient::global([BM_URL => bmBody(
        [
            bmSession('1', 'Steamy', '2026-10-10T19:10:00Z', '2026-10-10T19:20:00Z'),
            bmSession('2', 'Bare', '2026-10-10T19:10:00Z', '2026-10-10T19:20:00Z'),
        ],
        [bmIdentifier('1', 'name', 'ignored'), bmIdentifier('1', 'steamID', '7656119000000001')],
    )]);

    [$start, $end] = bmWindow();
    $players = bm()->fetchPresence($start, $end)->players;

    expect(array_map(fn ($p) => $p->platformId, $players))->toBe(['7656119000000001', '2'])
        // The player number is marked as BattleMetrics' own, so it is never taken for a game ID.
        ->and(array_map(fn ($p) => $p->idSource, $players))->toBe([null, CrconPlayer::BATTLEMETRICS_PLAYER]);
});

it('lists a player who rejoined more than once exactly once', function () {
    MockClient::global([BM_URL => bmBody(
        [
            bmSession('1', 'Alpha', '2026-10-10T19:05:00Z', '2026-10-10T19:30:00Z'),
            bmSession('1', 'Alpha', '2026-10-10T19:40:00Z', '2026-10-10T20:30:00Z'),
        ],
        [bmIdentifier('1', 'reforgerUUID', 'u1')],
    )]);

    [$start, $end] = bmWindow();

    expect(bm()->fetchPresence($start, $end)->players)->toHaveCount(1);
});

it('excludes sessions wholly outside the window and keeps open ones', function () {
    MockClient::global([BM_URL => bmBody([
        bmSession('1', 'Before', '2026-10-10T17:00:00Z', '2026-10-10T18:59:00Z'),
        bmSession('2', 'After', '2026-10-10T21:01:00Z', '2026-10-10T22:00:00Z'),
        bmSession('3', 'Straddler', '2026-10-10T18:00:00Z', '2026-10-10T19:01:00Z'),
        bmSession('4', 'StillOn', '2026-10-10T20:00:00Z', null),
    ])]);

    [$start, $end] = bmWindow();
    $ids = array_map(fn ($p) => $p->platformId, bm()->fetchPresence($start, $end)->players);

    expect($ids)->toBe(['3', '4']);
});

it('returns an empty success, not a failure, when nobody was on the server', function () {
    MockClient::global([BM_URL => bmBody([])]);

    [$start, $end] = bmWindow();
    $presence = bm()->fetchPresence($start, $end);

    expect($presence)->toBeInstanceOf(CrconPresence::class)->and($presence->players)->toBe([]);
});

it('follows the next-page link until the history is complete', function () {
    $mock = MockClient::global([
        BM_URL => function (PendingRequest $request) {
            return str_contains($request->getUri()->getQuery(), 'page%5Boffset%5D=1')
                ? bmBody([bmSession('2', 'Bravo', '2026-10-10T19:10:00Z', '2026-10-10T19:20:00Z')])
                : bmBody([bmSession('1', 'Alpha', '2026-10-10T19:10:00Z', '2026-10-10T19:20:00Z')], [], 'https://bm.test/servers/42/relationships/sessions?page%5Boffset%5D=1');
        },
    ]);

    [$start, $end] = bmWindow();
    $ids = array_map(fn ($p) => $p->platformId, bm()->fetchPresence($start, $end)->players);

    expect($ids)->toBe(['1', '2']);
    $mock->assertSentCount(2);
});

it('refuses to follow a next-page link off the BattleMetrics host', function () {
    $mock = MockClient::global([BM_URL => bmBody([], [], 'https://evil.test/steal')]);

    [$start, $end] = bmWindow();

    try {
        bm()->fetchPresence($start, $end);
        $this->fail('Expected a BattleMetricsUnavailable.');
    } catch (BattleMetricsUnavailable $e) {
        expect($e->reason)->toBe(BattleMetricsFailureReason::Malformed);
    }

    $mock->assertSentCount(1);
});

it('authenticates with the configured key and only ever issues the session read', function () {
    $mock = MockClient::global([BM_URL => bmBody([])]);

    [$start, $end] = bmWindow();
    bm()->fetchPresence($start, $end);

    $mock->assertSent(function (Request $request, Response $response) {
        return $request->getMethod()->value === 'GET'
            && $response->getPendingRequest()->headers()->get('Authorization') === 'Bearer test-key'
            && str_contains($response->getPendingRequest()->getUri()->getPath(), '/servers/42/relationships/sessions');
    });
    $mock->assertSentCount(1);
});

it('raises a typed failure for every way BattleMetrics can be unavailable', function (array|string $body, int $status, string $reason) {
    MockClient::global([BM_URL => MockResponse::make($body, $status)]);

    [$start, $end] = bmWindow();

    try {
        bm()->fetchPresence($start, $end);
        $this->fail('Expected a BattleMetricsUnavailable.');
    } catch (BattleMetricsUnavailable $e) {
        expect($e->reason->value)->toBe($reason);
    }
})->with([
    'server error' => ['boom', 500, 'http_error'],
    'bad key' => [['errors' => []], 401, 'http_error'],
    'rate limited' => [['errors' => []], 429, 'http_error'],
    'not json' => ['<html>', 200, 'malformed'],
    'json scalar' => ['123', 200, 'malformed'],
    'no data list' => [['meta' => 1], 200, 'malformed'],
    'session without player' => [['data' => [['attributes' => ['start' => '2026-10-10T19:10:00Z', 'stop' => null]]]], 200, 'malformed'],
    'session without start' => [['data' => [['attributes' => ['stop' => null], 'relationships' => ['player' => ['data' => ['id' => '1']]]]]], 200, 'malformed'],
    'session not an object' => [['data' => ['x']], 200, 'malformed'],
    'session without attributes' => [['data' => [['relationships' => []]]], 200, 'malformed'],
    'unparseable start' => [['data' => [['attributes' => ['start' => 'garbage', 'stop' => null], 'relationships' => ['player' => ['data' => ['id' => '1']]]]]], 200, 'malformed'],
    'links not an object' => [['data' => [], 'links' => 'x'], 200, 'malformed'],
    'unparseable stop' => [['data' => [['attributes' => ['start' => '2026-10-10T19:10:00Z', 'stop' => 'garbage'], 'relationships' => ['player' => ['data' => ['id' => '1']]]]]], 200, 'malformed'],
]);

it('raises a typed failure when BattleMetrics cannot be reached', function () {
    MockClient::destroyGlobal();

    [$start, $end] = bmWindow();

    // Port 1 refuses immediately, so no network is needed.
    try {
        bm(['base_url' => 'http://127.0.0.1:1', 'api_key' => 'k', 'server_id' => '42'])->fetchPresence($start, $end);
        $this->fail('Expected a BattleMetricsUnavailable.');
    } catch (BattleMetricsUnavailable $e) {
        expect($e->reason)->toBe(BattleMetricsFailureReason::Unreachable)->and($e->getPrevious())->not->toBeNull();
    }
});

it('reports missing configuration as a deployment fault without sending anything', function (?array $config) {
    $mock = MockClient::global([BM_URL => bmBody([])]);

    [$start, $end] = bmWindow();

    try {
        bm($config)->fetchPresence($start, $end);
        $this->fail('Expected a BattleMetricsUnavailable.');
    } catch (BattleMetricsUnavailable $e) {
        expect($e->reason)->toBe(BattleMetricsFailureReason::Misconfigured);
    }

    $mock->assertNothingSent();
})->with([
    'no config block' => [null],
    'empty key' => [['base_url' => 'https://bm.test', 'api_key' => '', 'server_id' => '42']],
    'empty server id' => [['base_url' => 'https://bm.test', 'api_key' => 'k', 'server_id' => '']],
    'empty url' => [['base_url' => '', 'api_key' => 'k', 'server_id' => '42']],
]);

it('rejects a window that ends before it starts', function () {
    [$start, $end] = bmWindow();

    expect(fn () => bm()->fetchPresence($end, $start))->toThrow(BattleMetricsUnavailable::class);
});

it('resolves from the container with the battlemetrics config', function () {
    config(['services.battlemetrics' => ['base_url' => 'https://bm.test', 'api_key' => 'test-key', 'server_id' => '42']]);
    MockClient::global([BM_URL => bmBody([])]);

    [$start, $end] = bmWindow();

    expect(app(BattleMetricsApi::class))->toBe(app(BattleMetricsApi::class))
        ->and(app(BattleMetricsApi::class)->fetchPresence($start, $end)->players)->toBe([]);
});

it('fails rather than return a partial list when paging never ends', function () {
    $mock = MockClient::global([BM_URL => function (PendingRequest $request) {
        static $n = 0;

        return bmBody([], [], 'https://bm.test/servers/42/relationships/sessions?page%5Boffset%5D='.++$n);
    }]);

    [$start, $end] = bmWindow();

    try {
        bm()->fetchPresence($start, $end);
        $this->fail('Expected a BattleMetricsUnavailable.');
    } catch (BattleMetricsUnavailable $e) {
        expect($e->reason)->toBe(BattleMetricsFailureReason::Malformed);
    }

    $mock->assertSentCount(50);
});

it('queries the window in UTC and treats the window edges as inclusive', function () {
    $mock = MockClient::global([BM_URL => bmBody([
        bmSession('1', 'EndsAtStart', '2026-10-10T18:00:00Z', '2026-10-10T19:00:00Z'),
        bmSession('2', 'StartsAtEnd', '2026-10-10T21:00:00Z', '2026-10-10T22:00:00Z'),
    ])]);

    $start = new DateTimeImmutable('2026-10-10 21:00:00', new DateTimeZone('Europe/Brussels'));
    $end = new DateTimeImmutable('2026-10-10 23:00:00', new DateTimeZone('Europe/Brussels'));
    $ids = array_map(fn ($p) => $p->platformId, bm()->fetchPresence($start, $end)->players);

    expect($ids)->toBe(['1', '2']);
    $mock->assertSent(function (Request $request, Response $response) {
        $query = $response->getPendingRequest()->query()->all();

        return $query['start'] === '2026-10-10T19:00:00Z' && $query['stop'] === '2026-10-10T21:00:00Z'
            && $query['include'] === 'identifier' && $query['page[size]'] === 100;
    });
});

it('fails when the next-page link does not advance', function () {
    $mock = MockClient::global([BM_URL => bmBody([], [], 'https://bm.test/servers/42/relationships/sessions?page%5Boffset%5D=1')]);

    [$start, $end] = bmWindow();

    expect(fn () => bm()->fetchPresence($start, $end))->toThrow(BattleMetricsUnavailable::class);
    $mock->assertSentCount(2);
});

it('keeps the window filters on later pages and resolves identifiers across pages', function () {
    $mock = MockClient::global([
        BM_URL => function (PendingRequest $request) {
            return str_contains($request->getUri()->getQuery(), 'cursor')
                ? bmBody([], [bmIdentifier('1', 'steamID', '7656119000000001')])
                : bmBody([bmSession('1', 'Alpha', '2026-10-10T19:10:00Z', '2026-10-10T19:20:00Z')], [], 'https://bm.test/servers/42/relationships/sessions?cursor=abc');
        },
    ]);

    [$start, $end] = bmWindow();
    $players = bm()->fetchPresence($start, $end)->players;

    expect($players)->toHaveCount(1)->and($players[0]->platformId)->toBe('7656119000000001');
    $mock->assertSent(fn (Request $r, Response $res) => str_contains($res->getPendingRequest()->getUri()->getQuery(), 'cursor') && str_contains($res->getPendingRequest()->getUri()->getQuery(), 'stop='));
});

it('rejects loosely formatted timestamps', function () {
    MockClient::global([BM_URL => MockResponse::make(['data' => [['attributes' => ['start' => 'now', 'stop' => null], 'relationships' => ['player' => ['data' => ['id' => '1']]]]]])]);

    [$start, $end] = bmWindow();

    expect(fn () => bm()->fetchPresence($start, $end))->toThrow(BattleMetricsUnavailable::class);
});

it('keeps the window when a next-page link carries its own start, stop and include', function () {
    $mock = MockClient::global([
        BM_URL => function (PendingRequest $request) {
            return str_contains($request->getUri()->getQuery(), 'cursor')
                ? bmBody([])
                : bmBody([], [], 'https://bm.test/servers/42/relationships/sessions?cursor=abc&start=2000-01-01T00:00:00Z&stop=2099-01-01T00:00:00Z&include=player');
        },
    ]);

    [$start, $end] = bmWindow();
    bm()->fetchPresence($start, $end);

    $mock->assertSentCount(2);
    $mock->assertSent(function (Request $request, Response $response) {
        $query = $response->getPendingRequest()->query()->all();

        return ($query['cursor'] ?? null) === 'abc'
            && $query['start'] === '2026-10-10T19:00:00Z' && $query['stop'] === '2026-10-10T21:00:00Z' && $query['include'] === 'identifier';
    });
});
