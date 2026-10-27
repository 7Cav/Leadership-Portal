<?php

use App\Integration\BattleMetrics\BattleMetricsApi;
use App\Integration\BattleMetrics\BattleMetricsFailureReason;
use App\Integration\BattleMetrics\BattleMetricsUnavailable;
use App\Integration\Crcon\CrconApi;
use App\Integration\Crcon\CrconFailureReason;
use App\Integration\Crcon\CrconUnavailable;
use App\Integration\ListedServer;
use App\Integration\Nightwatch\NightwatchApi;
use App\Integration\Nightwatch\NightwatchFailureReason;
use App\Integration\Nightwatch\NightwatchUnavailable;
use Carbon\CarbonImmutable;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

/**
 * #389 — each seam lists the game servers it can see, and reads the one server it is given. Every call is faked with
 * Saloon's MockClient and invented ids; nothing here touches a real API.
 */
function listed(array $servers): array
{
    return array_map(fn (ListedServer $s) => [$s->externalId, $s->name], $servers);
}

function window389(): array
{
    return [new DateTimeImmutable('2026-10-10 19:00:00', new DateTimeZone('UTC')), new DateTimeImmutable('2026-10-10 21:00:00', new DateTimeZone('UTC'))];
}

describe('BattleMetrics', function () {
    $api = fn () => new BattleMetricsApi(['base_url' => 'https://bm.test', 'api_key' => 'k', 'server_id' => null]);

    it('lists the servers the key has RCON access to, following pages', function () use ($api) {
        $sent = [];
        MockClient::global([
            'bm.test/servers*' => function (PendingRequest $request) use (&$sent) {
                $sent[] = $request->query()->all();

                return count($sent) === 1
                    ? MockResponse::make(['data' => [['type' => 'server', 'id' => '11', 'attributes' => ['name' => ' 7Cav Training ']]], 'links' => ['next' => 'https://bm.test/servers?page[key]=abc&filter[rcon]=true']])
                    : MockResponse::make(['data' => [['type' => 'server', 'id' => 12, 'attributes' => ['name' => '']]], 'links' => []]);
            },
        ]);

        expect(listed($api()->listServers()))->toBe([['11', '7Cav Training'], ['12', 'BattleMetrics server 12']])
            ->and($sent[0]['filter[rcon]'] ?? $sent[0]['filter']['rcon'] ?? null)->toBe('true')
            ->and($sent[1]['filter[rcon]'] ?? $sent[1]['filter']['rcon'] ?? null)->toBe('true');
    });

    it('refuses a listing entry without an id, and a next link off the host', function (array $body) use ($api) {
        MockClient::global(['bm.test/servers*' => MockResponse::make($body)]);

        expect(fn () => $api()->listServers())->toThrow(fn (BattleMetricsUnavailable $e) => expect($e->reason)->toBe(BattleMetricsFailureReason::Malformed));
    })->with([
        'no id' => [['data' => [['type' => 'server', 'attributes' => ['name' => 'x']]]]],
        'foreign next' => [['data' => [], 'links' => ['next' => 'https://evil.test/servers?page[key]=1']]],
    ]);

    it('needs no BATTLEMETRICS_SERVER_ID to list, but does need the key', function () {
        expect(fn () => (new BattleMetricsApi(['base_url' => 'https://bm.test', 'api_key' => '']))->listServers())
            ->toThrow(fn (BattleMetricsUnavailable $e) => expect($e->reason)->toBe(BattleMetricsFailureReason::Misconfigured));
    });

    it('reads the server it is given instead of the env one', function () use ($api) {
        MockClient::global([
            'bm.test/servers/77/relationships/sessions*' => MockResponse::make(['data' => [[
                'type' => 'session', 'id' => 's1',
                'attributes' => ['name' => 'Alpha', 'start' => '2026-10-10T19:10:00Z', 'stop' => '2026-10-10T20:00:00Z'],
                'relationships' => ['player' => ['data' => ['type' => 'player', 'id' => '9']]],
            ]], 'links' => []]),
        ]);

        expect($api()->fetchPresence(...[...window389(), '77'])->players)->toHaveCount(1);
    });

    it('still reads the env server when none is given, and says so when it is unset', function () use ($api) {
        expect(fn () => $api()->fetchPresence(...window389()))
            ->toThrow(BattleMetricsUnavailable::class, 'BATTLEMETRICS_SERVER_ID is empty');
    });
});

describe('Nightwatch', function () {
    beforeEach(fn () => CarbonImmutable::setTestNow('2026-10-10 22:00:00'));
    afterEach(fn () => CarbonImmutable::setTestNow());

    $api = fn () => new NightwatchApi(['base_url' => 'https://nw.test/api/public/stats', 'api_key' => 'k', 'server_ids' => '']);

    it('lists the workspace servers from a `servers` list or a bare list', function (array $body) use ($api) {
        MockClient::global(['nw.test/api/public/stats/servers/live' => MockResponse::make($body)]);

        expect(listed($api()->listServers()))->toBe([['srv-1', 'Tac 1'], ['srv-2', 'Nightwatch server srv-2']]);
    })->with([
        'wrapped' => [['servers' => [['id' => 'srv-1', 'name' => 'Tac 1'], ['id' => 'srv-2']]]],
        'bare' => [[['serverId' => 'srv-1', 'serverName' => 'Tac 1'], ['serverId' => 'srv-2', 'name' => ' ']]],
    ]);

    it('refuses a listing it cannot read as servers', function (mixed $body) use ($api) {
        MockClient::global(['nw.test/api/public/stats/servers/live' => MockResponse::make($body)]);

        expect(fn () => $api()->listServers())->toThrow(fn (NightwatchUnavailable $e) => expect($e->reason)->toBe(NightwatchFailureReason::Malformed));
    })->with([
        'no servers key' => [['data' => []]],
        // `{}`, `[]` and an empty body all decode to [], which is no listing at all.
        'an empty body' => [[]],
        'servers not a list' => [['servers' => ['a' => ['id' => 'srv-1']]]],
        'server without id' => [['servers' => [['name' => 'x']]]],
    ]);

    it('reads the server it is given, though it is not in NIGHTWATCH_SERVER_IDS', function () use ($api) {
        MockClient::global(['nw.test/api/public/stats/matches*' => MockResponse::make(['matches' => [[
            'serverId' => 'srv-9', 'startedAtUtc' => '2026-10-10T19:10:00Z', 'endedAtUtc' => '2026-10-10T20:00:00Z', 'live' => false,
            'board' => ['scoreboard' => [['steamId' => '1001', 'name' => 'Alpha']]],
        ]]])]);

        expect($api()->fetchPresence(...[...window389(), 'srv-9'])->players)->toHaveCount(1);
    });
});

describe('CRCON', function () {
    $api = fn () => new CrconApi(['base_url' => 'https://crcon.test', 'api_key' => 'k']);

    it('lists its one server by the name it gives itself', function (mixed $name) use ($api) {
        MockClient::global(['crcon.test/api/get_public_info' => MockResponse::make(['result' => ['name' => $name], 'failed' => false, 'error' => null])]);

        expect(listed($api()->listServers()))->toBe([[CrconApi::SERVER_ID, '7Cav HLL Training']]);
    })->with([
        'nested' => [['name' => '7Cav HLL Training', 'short_name' => '7Cav']],
        'string' => ['7Cav HLL Training '],
    ]);

    it('refuses public info without a name', function () use ($api) {
        MockClient::global(['crcon.test/api/get_public_info' => MockResponse::make(['result' => ['name' => ['short_name' => 'x']], 'failed' => false, 'error' => null])]);

        expect(fn () => $api()->listServers())->toThrow(fn (CrconUnavailable $e) => expect($e->reason)->toBe(CrconFailureReason::Malformed));
    });
});
