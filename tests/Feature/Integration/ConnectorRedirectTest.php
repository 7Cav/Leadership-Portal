<?php

use App\Commands\Auth\CompleteForumLogin;
use App\Commands\Auth\ForumLoginException;
use App\Integration\BattleMetrics\BattleMetricsApi;
use App\Integration\BattleMetrics\BattleMetricsConnector;
use App\Integration\BattleMetrics\BattleMetricsFailureReason;
use App\Integration\BattleMetrics\BattleMetricsUnavailable;
use App\Integration\Cav\CavApi;
use App\Integration\Cav\CavConnector;
use App\Integration\Cav\CavRequestException;
use App\Integration\Crcon\CrconApi;
use App\Integration\Crcon\CrconConnector;
use App\Integration\Crcon\CrconFailureReason;
use App\Integration\Crcon\CrconUnavailable;
use App\Integration\Forum\ForumApi;
use App\Integration\Forum\ForumConnector;
use App\Integration\Forum\ForumRequestException;
use App\Integration\Nightwatch\NightwatchApi;
use App\Integration\Nightwatch\NightwatchConnector;
use App\Integration\Nightwatch\NightwatchFailureReason;
use App\Integration\Nightwatch\NightwatchRequestRefused;
use App\Integration\Nightwatch\NightwatchUnavailable;
use App\Repositories\UserRepository;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Every connector sends a key on each request. A redirect is never followed (a custom key header
 * would be replayed to the new host, and Nightwatch's path guard only sees the first URL), and a
 * 3xx answer is the integration's typed HTTP failure. Nothing here touches a real server.
 */
function redirectingEverything(): void
{
    MockClient::global(['*' => MockResponse::make('', 302, ['Location' => 'http://elsewhere.test/collect'])]);
}

function redirectWindow(): array
{
    $end = new DateTimeImmutable;

    return [$end->modify('-1 hour'), $end];
}

function redirectForum(): ForumApi
{
    return new ForumApi([
        'client_id' => 'id', 'client_secret' => 'secret', 'redirect_uri' => 'https://portal.test/cb',
        'base_url' => 'https://7cav.test', 'authorize_url' => 'https://7cav.test/oauth2/authorize', 'scopes' => ['user:read'],
        'api_key' => 'forum-key-123', 'loa_nodes' => [180 => 'loa'],
    ]);
}

it('turns off redirect following on every connector', function () {
    foreach ([
        new ForumConnector('https://7cav.test'),
        new CavConnector('https://cav.test', 'k'),
        new CrconConnector('https://crcon.test', 'k'),
        new BattleMetricsConnector('https://bm.test', 'k'),
        new NightwatchConnector('https://nw.test/api/public/stats', 'k'),
    ] as $connector) {
        expect($connector->config()->get('allow_redirects'))->toBeFalse();
    }
});

it('treats a forum redirect as a failed call', function () {
    redirectingEverything();

    expect(fn () => redirectForum()->listLoaThreads(180))->toThrow(ForumRequestException::class, 'HTTP 302')
        ->and(fn () => redirectForum()->exchangeCode('code'))->toThrow(ForumRequestException::class, 'HTTP 302');
});

it('treats a Cav redirect as a failed call', function () {
    redirectingEverything();

    try {
        (new CavApi(['base_url' => 'https://cav.test', 'api_key' => 'cav7_test-key']))->fetchProfileByCavId(5);
        $this->fail('expected a typed failure');
    } catch (CavRequestException $e) {
        expect($e->status)->toBe(302);
    }
});

it('treats a CRCON redirect as a failed call', function () {
    redirectingEverything();

    try {
        (new CrconApi(['base_url' => 'https://crcon.test', 'api_key' => 'k']))->fetchPresence(...redirectWindow());
        $this->fail('expected a typed failure');
    } catch (CrconUnavailable $e) {
        expect($e->reason)->toBe(CrconFailureReason::HttpError)->and($e->status)->toBe(302);
    }
});

it('treats a BattleMetrics redirect as a failed call', function () {
    redirectingEverything();

    try {
        (new BattleMetricsApi(['base_url' => 'https://bm.test', 'api_key' => 'k', 'server_id' => '42']))->fetchPresence(...redirectWindow());
        $this->fail('expected a typed failure');
    } catch (BattleMetricsUnavailable $e) {
        expect($e->reason)->toBe(BattleMetricsFailureReason::HttpError)->and($e->status)->toBe(302);
    }
});

it('treats a Nightwatch redirect as a failed call', function () {
    redirectingEverything();

    try {
        (new NightwatchApi(['base_url' => 'https://nw.test/api/public/stats', 'api_key' => 'k', 'server_ids' => 'srv-1']))->fetchPresence(...redirectWindow());
        $this->fail('expected a typed failure');
    } catch (NightwatchUnavailable $e) {
        expect($e->reason)->toBe(NightwatchFailureReason::HttpError)->and($e->status)->toBe(302);
    }
});

describe('with exception arguments in traces', function () {
    beforeEach(function () {
        $this->ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
    });

    afterEach(fn () => ini_set('zend.exception_ignore_args', $this->ignoreArgs));

    it('keeps the Nightwatch key out of a refused request\'s trace', function () {
        $api = new NightwatchApi(['base_url' => 'https://nw.test/api/v2', 'api_key' => 'NWKEY-0123456789-REST', 'server_ids' => 'srv-1']);

        try {
            $api->fetchPresence(...redirectWindow());
            $this->fail('expected a refusal');
        } catch (NightwatchRequestRefused $e) {
            expect((string) $e)->not->toContain('NWKEY-');
        }
    });

    it('keeps the OAuth code out of a failed sign-in\'s trace', function () {
        redirectingEverything();

        try {
            (new CompleteForumLogin(redirectForum(), app(UserRepository::class)))->handle('OAUTHCODE-0123456789-REST');
            $this->fail('expected a failed sign-in');
        } catch (ForumLoginException $e) {
            expect((string) $e)->not->toContain('OAUTHCODE-');
        }
    });

    it('keeps the BattleMetrics key out of an unreachable call\'s trace', function () {
        $api = new BattleMetricsApi(['base_url' => 'http://127.0.0.1:1', 'api_key' => 'BMKEY-0123456789-REST', 'server_id' => '42']);

        try {
            $api->fetchPresence(...redirectWindow());
            $this->fail('expected a typed failure');
        } catch (BattleMetricsUnavailable $e) {
            expect($e->reason)->toBe(BattleMetricsFailureReason::Unreachable)
                ->and((string) $e)->not->toContain('BMKEY-');
        }
    });
});
