<?php

use App\Integration\Crcon\Data\CrconPresence;
use App\Integration\Nightwatch\NightwatchApi;
use App\Integration\Nightwatch\NightwatchConnector;
use App\Integration\Nightwatch\NightwatchFailureReason;
use App\Integration\Nightwatch\NightwatchRequestRefused;
use App\Integration\Nightwatch\NightwatchUnavailable;
use App\Integration\Nightwatch\NightwatchWindowExpired;
use App\Integration\Nightwatch\Requests\FetchMatchesRequest;
use Carbon\CarbonImmutable;
use Saloon\Enums\Method;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

/**
 * Story 7.10 — the Nightwatch read seam. Every call is faked with Saloon's MockClient and uses
 * invented ids and names; nothing here touches the real API.
 */
const NW_BASE = 'https://nw.test/api/public/stats';
const NW_URL = 'nw.test/api/public/stats/matches*';

function nw(?array $config = ['base_url' => NW_BASE, 'api_key' => 'test-key', 'server_ids' => 'srv-1']): NightwatchApi
{
    return new NightwatchApi($config);
}

function nwWindow(): array
{
    return [new DateTimeImmutable('2026-10-10 19:00:00', new DateTimeZone('UTC')), new DateTimeImmutable('2026-10-10 21:00:00', new DateTimeZone('UTC'))];
}

function nwMatch(string $start, ?string $end, array $players, string $server = 'srv-1', bool $live = false): array
{
    return [
        'id' => 'm-'.$start,
        'serverId' => $server,
        'startedAtUtc' => $start,
        'endedAtUtc' => $end,
        'live' => $live,
        'board' => ['scoreboard' => array_map(fn ($name, $id) => ['steamId' => $id, 'name' => $name], array_keys($players), $players)],
    ];
}

function nwBody(array $matches): MockResponse
{
    return MockResponse::make(['serverId' => 'srv-1', 'matches' => $matches, 'generatedAtUtc' => '2026-10-10T22:00:00Z']);
}

beforeEach(fn () => CarbonImmutable::setTestNow('2026-10-10 22:00:00'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('returns scoreboard players of overlapping matches as the shared presence DTO', function () {
    MockClient::global([NW_URL => nwBody([nwMatch('2026-10-10T19:10:00Z', '2026-10-10T20:00:00Z', ['Alpha' => '1001'])])]);

    [$start, $end] = nwWindow();
    $presence = nw()->fetchPresence($start, $end);

    expect($presence)->toBeInstanceOf(CrconPresence::class)
        ->and($presence->players)->toHaveCount(1)
        ->and($presence->players[0]->playerName)->toBe('Alpha')
        ->and($presence->players[0]->platformId)->toBe('1001')
        ->and($presence->windowStart)->toEqual($start)
        ->and($presence->windowEnd)->toEqual($end);
});

it('sends the key as X-Api-Key and filters by server', function () {
    $mock = MockClient::global([NW_URL => nwBody([])]);

    [$start, $end] = nwWindow();
    nw()->fetchPresence($start, $end);

    $mock->assertSent(fn (Request $r, $response) => $response->getPendingRequest()->headers()->get('X-Api-Key') === 'test-key'
        && $response->getPendingRequest()->query()->get('server') === 'srv-1'
        && $r->getMethod() === Method::GET);
});

it('dedupes a player seen in several matches by steam id, keeping the first name', function () {
    MockClient::global([NW_URL => nwBody([
        nwMatch('2026-10-10T19:10:00Z', '2026-10-10T19:50:00Z', ['[7CAV] Old' => '1001', 'Beta' => '1002']),
        nwMatch('2026-10-10T20:00:00Z', '2026-10-10T20:40:00Z', ['=7Cav=PVT.New' => '1001']),
    ])]);

    [$start, $end] = nwWindow();
    $players = nw()->fetchPresence($start, $end)->players;

    expect($players)->toHaveCount(2)
        ->and(array_map(fn ($p) => $p->platformId, $players))->toBe(['1001', '1002'])
        ->and($players[0]->playerName)->toBe('[7CAV] Old');
});

it('counts overlap strictly and treats a live match as running until now', function () {
    MockClient::global([NW_URL => nwBody([
        nwMatch('2026-10-10T17:00:00Z', '2026-10-10T19:00:00Z', ['EndsAtStart' => '1'], 'srv-1'),
        nwMatch('2026-10-10T21:00:00Z', '2026-10-10T22:00:00Z', ['StartsAtEnd' => '2']),
        nwMatch('2026-10-10T18:00:00Z', '2026-10-10T19:00:01Z', ['Overlaps' => '3']),
        nwMatch('2026-10-10T20:30:00Z', '2026-10-10T22:00:00Z', ['Live' => '4'], live: true),
        nwMatch('2026-10-10T21:30:00Z', '2026-10-10T22:00:00Z', ['LiveTooLate' => '5'], live: true),
    ])]);

    [$start, $end] = nwWindow();

    expect(array_map(fn ($p) => $p->platformId, nw()->fetchPresence($start, $end)->players))->toBe(['3', '4']);
});

it('ignores matches from a server other than the requested one', function () {
    MockClient::global([NW_URL => nwBody([nwMatch('2026-10-10T19:10:00Z', '2026-10-10T20:00:00Z', ['Other' => '9'], 'srv-other')])]);

    [$start, $end] = nwWindow();

    expect(nw()->fetchPresence($start, $end)->players)->toBe([]);
});

it('merges every configured server unless one is named', function () {
    $two = nwBody([nwMatch('2026-10-10T19:10:00Z', '2026-10-10T20:00:00Z', ['Two' => '2'], 'srv-2')]);
    MockClient::global([
        nwBody([nwMatch('2026-10-10T19:10:00Z', '2026-10-10T20:00:00Z', ['One' => '1'], 'srv-1')]),
        $two,
        $two,
    ]);

    [$start, $end] = nwWindow();
    $api = nw(['base_url' => NW_BASE, 'api_key' => 'k', 'server_ids' => ' srv-1, srv-2 ,']);

    expect($api->fetchPresence($start, $end)->players)->toHaveCount(2)
        ->and($api->fetchPresence($start, $end, 'srv-2')->players)->toHaveCount(1);

    expect(fn () => $api->fetchPresence($start, $end, 'srv-x'))
        ->toThrow(NightwatchUnavailable::class);
});

it('returns an empty success for a readable window with no overlapping match', function () {
    MockClient::global([NW_URL => nwBody([nwMatch('2026-10-09T10:00:00Z', '2026-10-09T11:00:00Z', ['Early' => '1']), nwMatch('2026-10-10T19:30:00Z', '2026-10-10T20:00:00Z', [])])]);

    [$start, $end] = nwWindow();

    expect(nw()->fetchPresence($start, $end)->players)->toBe([]);
});

it('raises NightwatchWindowExpired, never an empty list, once the window is past retention', function () {
    $mock = MockClient::global([NW_URL => nwBody([])]);

    $end = new DateTimeImmutable('2026-10-08 21:59:59', new DateTimeZone('UTC'));

    expect(fn () => nw()->fetchPresence($end->modify('-2 hours'), $end))->toThrow(NightwatchWindowExpired::class);
    $mock->assertNothingSent();

    $edge = new DateTimeImmutable('2026-10-08 22:00:00', new DateTimeZone('UTC'));
    expect(nw()->fetchPresence($edge->modify('-2 hours'), $edge)->players)->toBe([]);
});

it('raises a typed failure for an error status', function (int $status) {
    MockClient::global([NW_URL => MockResponse::make(['error' => 'x'], $status)]);

    [$start, $end] = nwWindow();

    try {
        nw()->fetchPresence($start, $end);
        $this->fail('expected NightwatchUnavailable');
    } catch (NightwatchUnavailable $e) {
        expect($e->reason)->toBe(NightwatchFailureReason::HttpError)->and($e->status)->toBe($status);
    }
})->with([401, 403, 429, 500, 503]);

it('raises a typed failure when the connection fails', function () {
    MockClient::global([NW_URL => MockResponse::make()->throw(fn ($pending) => new FatalRequestException(new RuntimeException('timeout'), $pending))]);

    [$start, $end] = nwWindow();

    expect(fn () => nw()->fetchPresence($start, $end))->toThrow(NightwatchUnavailable::class);
});

it('raises a typed failure for malformed bodies', function (mixed $body) {
    MockClient::global([NW_URL => MockResponse::make($body)]);

    [$start, $end] = nwWindow();

    try {
        nw()->fetchPresence($start, $end);
        $this->fail('expected NightwatchUnavailable');
    } catch (NightwatchUnavailable $e) {
        expect($e->reason)->toBe(NightwatchFailureReason::Malformed);
    }
})->with([
    'not json' => ['<html>'],
    'json scalar' => ['"hello"'],
    'no matches' => [['serverId' => 'srv-1']],
    'match without start' => [['matches' => [['serverId' => 'srv-1', 'endedAtUtc' => '2026-10-10T20:00:00Z', 'board' => ['scoreboard' => []]]]]],
    'bad start' => [['matches' => [['serverId' => 'srv-1', 'startedAtUtc' => 'now', 'endedAtUtc' => '2026-10-10T20:00:00Z', 'board' => ['scoreboard' => []]]]]],
    'finished match without end' => [['matches' => [['serverId' => 'srv-1', 'startedAtUtc' => '2026-10-10T19:00:00Z', 'endedAtUtc' => null, 'live' => false, 'board' => ['scoreboard' => []]]]]],
    'no scoreboard' => [['matches' => [['serverId' => 'srv-1', 'startedAtUtc' => '2026-10-10T19:00:00Z', 'endedAtUtc' => '2026-10-10T20:00:00Z']]]],
    'row without steam id' => [['matches' => [['serverId' => 'srv-1', 'startedAtUtc' => '2026-10-10T19:00:00Z', 'endedAtUtc' => '2026-10-10T20:00:00Z', 'board' => ['scoreboard' => [['name' => 'x']]]]]]],
    'match without server id' => [['matches' => [['startedAtUtc' => '2026-10-10T19:00:00Z', 'endedAtUtc' => '2026-10-10T20:00:00Z', 'board' => ['scoreboard' => []]]]]],
    'truncated at the limit' => [['matches' => array_fill(0, 100, nwMatch('2026-10-01T00:00:00Z', '2026-10-01T01:00:00Z', []))]],
]);

it('falls back to the steam id when a name is blank', function () {
    MockClient::global([NW_URL => nwBody([nwMatch('2026-10-10T19:10:00Z', '2026-10-10T20:00:00Z', ['  ' => '1001'])])]);

    [$start, $end] = nwWindow();

    expect(nw()->fetchPresence($start, $end)->players[0]->playerName)->toBe('1001');
});

it('reports missing configuration as a typed failure without sending anything', function (?array $config) {
    $mock = MockClient::global([NW_URL => nwBody([])]);

    [$start, $end] = nwWindow();

    try {
        nw($config)->fetchPresence($start, $end);
        $this->fail('expected NightwatchUnavailable');
    } catch (NightwatchUnavailable $e) {
        expect($e->reason)->toBe(NightwatchFailureReason::Misconfigured);
    }

    $mock->assertNothingSent();
})->with([
    'no config block' => [null],
    'no key' => [['base_url' => NW_BASE, 'api_key' => '', 'server_ids' => 'a']],
    'no servers' => [['base_url' => NW_BASE, 'api_key' => 'k', 'server_ids' => ' , ']],
    'no base url' => [['base_url' => '', 'api_key' => 'k', 'server_ids' => 'a']],
]);

it('rejects a window that ends before it starts', function () {
    [$start, $end] = nwWindow();

    expect(fn () => nw()->fetchPresence($end, $start))->toThrow(NightwatchUnavailable::class);
});

describe('read-only guard', function () {
    it('refuses any method but GET before a request leaves', function (Method $method) {
        $mock = MockClient::global(['*' => MockResponse::make([])]);
        $request = new class($method) extends Request
        {
            public function __construct(Method $method)
            {
                $this->method = $method;
            }

            public function resolveEndpoint(): string
            {
                return '/matches';
            }
        };

        expect(fn () => (new NightwatchConnector(NW_BASE, 'k'))->send($request))->toThrow(NightwatchRequestRefused::class);
        $mock->assertNothingSent();
    })->with([Method::POST, Method::PUT, Method::PATCH, Method::DELETE]);

    it('refuses paths outside the stats base before a request leaves', function (string $endpoint) {
        $mock = MockClient::global(['*' => MockResponse::make([])]);
        $request = new class($endpoint) extends Request
        {
            protected Method $method = Method::GET;

            public function __construct(private string $endpoint) {}

            public function resolveEndpoint(): string
            {
                return $this->endpoint;
            }
        };

        expect(fn () => (new NightwatchConnector(NW_BASE, 'k'))->send($request))->toThrow(NightwatchRequestRefused::class);
        $mock->assertNothingSent();
    })->with(['/../quests', '/%2e%2e/quests', '/a/../../x']);

    it('refuses a base that is not the stats API', function () {
        MockClient::global(['*' => MockResponse::make([])]);

        expect(fn () => (new NightwatchConnector('https://nw.test/api/admin', 'k'))->send(new FetchMatchesRequest('s')))
            ->toThrow(NightwatchRequestRefused::class);
    });

    it('lets a GET under the base through', function () {
        MockClient::global(['*' => MockResponse::make(['matches' => []])]);

        expect((new NightwatchConnector(NW_BASE, 'k'))->send(new FetchMatchesRequest('s'))->status())->toBe(200);
    });

    it('does not disguise a refused request as an outage', function () {
        MockClient::global(['*' => MockResponse::make([])]);
        $api = new NightwatchApi(['base_url' => 'https://nw.test/api/admin', 'api_key' => 'k', 'server_ids' => 's']);

        [$start, $end] = nwWindow();

        expect(fn () => $api->fetchPresence($start, $end))->toThrow(NightwatchRequestRefused::class);
    });
});
