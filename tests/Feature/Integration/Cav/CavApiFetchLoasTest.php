<?php

use App\Integration\Cav\CavApi;
use App\Integration\Cav\CavFailureReason;
use App\Integration\Cav\CavRequestException;
use App\Integration\Cav\Data\CavLoa;
use Carbon\CarbonImmutable;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

/**
 * #690 — `CavApi::fetchLoas()`, the read of the roster API's LOA list the leave sync is built on. Faked at the AD-3
 * seam by URL, like the other Cav seam tests. The record shape is the one in the API's OpenAPI spec (LoaService_ListLoas).
 */
const LOAS_URL = 'api.7cav.us/api/v1/loas*';

function loasCav(array $config = []): CavApi
{
    return new CavApi(['base_url' => 'https://api.7cav.us', 'api_key' => 'cav7_test-key', ...$config]);
}

/** One LOA as the API sends it, with `$overrides` replacing whole fields. */
function apiLoa(array $overrides = []): array
{
    return [
        'threadId' => '101150',
        'forumUrl' => 'https://7cav.us/threads/101150/',
        'postDate' => 1791100000,
        'forum' => 'LOA_FORUM_1_7',
        'subject' => ['userId' => '4453', 'username' => 'Colt.DL', 'milpacId' => '2246'],
        'startDate' => '2026-11-02',
        'endDate' => '2026-11-09',
        'state' => 'LOA_STATE_OPEN',
        'reviewReason' => 'LOA_REVIEW_REASON_UNSPECIFIED',
        'reason' => "Work trip.\nBack Monday.",
        ...$overrides,
    ];
}

it('reads the LOA list into immutable records', function () {
    MockClient::global([LOAS_URL => MockResponse::make(['loas' => [apiLoa()]])]);

    $result = loasCav()->fetchLoas();
    $loa = $result->loas[0];

    expect($result->isComplete())->toBeTrue()
        ->and($loa)->toBeInstanceOf(CavLoa::class)
        ->and($loa->threadId)->toBe(101150)
        ->and($loa->forumUrl)->toBe('https://7cav.us/threads/101150/')
        ->and($loa->postedAt->getTimestamp())->toBe(1791100000)
        ->and($loa->forum)->toBe('1-7')
        ->and($loa->forumUserId)->toBe(4453)
        ->and($loa->cavId)->toBe(2246)
        ->and($loa->username)->toBe('Colt.DL')
        ->and($loa->startsOn->toDateString())->toBe('2026-11-02')
        ->and($loa->endsOn->toDateString())->toBe('2026-11-09')
        ->and($loa->needsReview)->toBeFalse()
        ->and($loa->reviewReason)->toBeNull()
        ->and($loa->reason)->toBe("Work trip.\nBack Monday.");

    expect(fn () => $loa->username = 'x')->toThrow(Error::class);
});

it('asks for the LOAs that ended since a day, as a UTC date, and with no query otherwise', function () {
    $queries = [];
    MockClient::global([LOAS_URL => function (PendingRequest $request) use (&$queries) {
        $queries[] = $request->query()->all();

        return MockResponse::make(['loas' => []]);
    }]);

    loasCav()->fetchLoas(CarbonImmutable::parse('2026-11-09 23:30', 'America/New_York'));
    loasCav()->fetchLoas();

    // 23:30 in New York is already the next day in UTC: the API reads the date as UTC.
    expect($queries)->toBe([['endedSince' => '2026-11-10'], []]);
});

it('sends the key as a bearer token', function () {
    $auth = null;
    MockClient::global([LOAS_URL => function (PendingRequest $request) use (&$auth) {
        $auth = $request->headers()->get('Authorization');

        return MockResponse::make(['loas' => []]);
    }]);

    loasCav()->fetchLoas();

    expect($auth)->toBe('Bearer cav7_test-key');
});

it('treats an empty list as an answer: nobody is on leave', function () {
    MockClient::global([LOAS_URL => MockResponse::make(['loas' => []])]);

    $result = loasCav()->fetchLoas();

    expect($result->loas)->toBe([])->and($result->isComplete())->toBeTrue();
});

it('names a needs-review LOA by the rule it tripped, and keeps the dates the API still gave', function (string $code, string $meaning) {
    MockClient::global([LOAS_URL => MockResponse::make(['loas' => [apiLoa(['state' => 'LOA_STATE_NEEDS_REVIEW', 'reviewReason' => $code])]])]);

    $loa = loasCav()->fetchLoas()->loas[0];

    expect($loa->needsReview)->toBeTrue()
        ->and($loa->reviewReason)->toBe($meaning)
        ->and($loa->startsOn)->not->toBeNull();
})->with([
    ['LOA_REVIEW_REASON_DATES_UNREADABLE', 'The dates could not be read.'],
    ['LOA_REVIEW_REASON_END_BEFORE_START', 'The end date is before the start date.'],
    ['LOA_REVIEW_REASON_TOO_LONG', 'The leave is longer than 14 days.'],
    ['LOA_REVIEW_REASON_NO_SUBJECT', 'The post has no Username.'],
    ['LOA_REVIEW_REASON_UNKNOWN_SUBJECT', 'The Username matches no member.'],
    ['LOA_REVIEW_REASON_SOMETHING_NEW', 'The roster API flagged the thread for review.'],
]);

it('reads the ids the API sends as "0" for a member it did not find as no id, and empty dates as none', function () {
    MockClient::global([LOAS_URL => MockResponse::make(['loas' => [apiLoa([
        'subject' => ['userId' => '0', 'username' => 'Nobody.X', 'milpacId' => '0'],
        'startDate' => '',
        'endDate' => '',
        'forumUrl' => '',
        'state' => 'LOA_STATE_NEEDS_REVIEW',
        'reviewReason' => 'LOA_REVIEW_REASON_UNKNOWN_SUBJECT',
    ])]])]);

    $loa = loasCav()->fetchLoas()->loas[0];

    expect($loa->forumUserId)->toBeNull()
        ->and($loa->cavId)->toBeNull()
        ->and($loa->username)->toBe('Nobody.X')
        ->and($loa->startsOn)->toBeNull()
        ->and($loa->endsOn)->toBeNull()
        ->and($loa->forumUrl)->toBeNull();
});

it('collects a record it cannot use instead of throwing, and says the list is not complete', function (array $broken) {
    MockClient::global([LOAS_URL => MockResponse::make(['loas' => [apiLoa(), $broken]])]);

    $result = loasCav()->fetchLoas();

    expect($result->loas)->toHaveCount(1)
        ->and($result->isComplete())->toBeFalse()
        ->and($result->errors)->toHaveCount(1);
})->with([
    'no thread id' => [apiLoa(['threadId' => '', 'forumUrl' => 'x'])],
    'a thread id of 0' => [apiLoa(['threadId' => '0'])],
    'no post date' => [apiLoa(['postDate' => null])],
    'a start date that is not a day' => [apiLoa(['startDate' => 'next Monday'])],
    'an impossible end date' => [apiLoa(['endDate' => '2026-02-30'])],
]);

it('collects an entry that is not an object', function () {
    MockClient::global([LOAS_URL => MockResponse::make(['loas' => [apiLoa(), 'oops']])]);

    $result = loasCav()->fetchLoas();

    expect($result->loas)->toHaveCount(1)->and($result->errors)->toHaveCount(1);
});

it('fails the whole call on a response that is not a list of LOAs', function (array|string $body) {
    MockClient::global([LOAS_URL => MockResponse::make($body)]);

    try {
        loasCav()->fetchLoas();
        test()->fail('Expected a CavRequestException.');
    } catch (CavRequestException $e) {
        expect($e->reason)->toBe(CavFailureReason::Malformed);
    }
})->with([
    'no loas key' => [['groups' => []]],
    'loas as a map' => [['loas' => ['a' => apiLoa()]]],
    'a json scalar' => ['"nope"'],
]);

it('fails with a hint about the scope on a 401 or 403, which is how a key without read:loas is refused', function (int $status) {
    MockClient::global([LOAS_URL => MockResponse::make(['errors' => [['code' => 'forbidden']]], $status)]);

    try {
        loasCav()->fetchLoas();
        test()->fail('Expected a CavRequestException.');
    } catch (CavRequestException $e) {
        expect($e->reason)->toBe(CavFailureReason::HttpError)
            ->and($e->status)->toBe($status)
            ->and($e->getMessage())->toContain("HTTP {$status}")->toContain('read:loas');
    }
})->with([401, 403]);

it('fails on a server error without the scope hint', function () {
    MockClient::global([LOAS_URL => MockResponse::make([], 500)]);

    try {
        loasCav()->fetchLoas();
        test()->fail('Expected a CavRequestException.');
    } catch (CavRequestException $e) {
        expect($e->getMessage())->toContain('HTTP 500')->not->toContain('read:loas');
    }
});

it('fails before sending anything when no key is configured, and says whether one is', function () {
    $client = MockClient::global([LOAS_URL => MockResponse::make(['loas' => []])]);

    expect(loasCav(['api_key' => null])->isConfigured())->toBeFalse()
        ->and(loasCav(['api_key' => '  '])->isConfigured())->toBeFalse()
        ->and(loasCav()->isConfigured())->toBeTrue()
        ->and(fn () => loasCav(['api_key' => ''])->fetchLoas())->toThrow(CavRequestException::class, 'CAV_API_KEY');

    $client->assertNothingSent();
});
