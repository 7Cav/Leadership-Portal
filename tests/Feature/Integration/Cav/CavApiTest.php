<?php

use App\Integration\Cav\CavApi;
use App\Integration\Cav\CavConnector;
use App\Integration\Cav\CavFailureReason;
use App\Integration\Cav\CavRequestException;
use App\Integration\Cav\Data\RosterType;
use App\Integration\Cav\Requests\FetchMilpacProfileRequest;
use App\Integration\Cav\Requests\FetchRosterRequest;
use Saloon\Enums\Method;

// Failure modes of the Cav seam that Saloon's MockResponse cannot express, because they happen
// below the response layer. These are the paths the whole degradation design rests on: if any of
// them escaped as something other than a CavRequestException, it would 500 a login.
//
// In the Feature suite rather than Unit despite testing one class in isolation: the seam logs
// skipped roster rows and roster failures through the Log facade, so it needs a booted app. No
// HTTP leaves here either — the transport tests dial a refused local port.

it('translates a transport failure into a CavRequestException, not a raw connection error', function () {
    // Port 1 refuses immediately, so this is fast and needs no network.
    $cav = new CavApi(['base_url' => 'http://127.0.0.1:1', 'api_key' => 'cav7_test-key']);

    try {
        $cav->fetchProfileByCavId(3);
        $this->fail('Expected a CavRequestException.');
    } catch (CavRequestException $e) {
        expect($e->reason)->toBe(CavFailureReason::Unreachable)
            ->and($e->getMessage())->toContain('could not be reached')
            ->and($e->getPrevious())->not->toBeNull();
    }
});

it('refuses an unusable base URL at the seam instead of letting a URI error escape', function () {
    // An operator setting CAV_API_BASE_URL= leaves '' here. Guzzle/PSR-7 would raise an
    // InvalidArgumentException from URI construction, which is not a CavRequestException and
    // would therefore 500 the login rather than degrade the session.
    $cav = new CavApi(['base_url' => '', 'api_key' => 'cav7_test-key']);

    try {
        $cav->fetchProfileByCavId(3);
        $this->fail('Expected a CavRequestException.');
    } catch (CavRequestException $e) {
        expect($e->reason)->toBe(CavFailureReason::Unreachable);
    }
});

it('reports a missing credential as a deployment fault, never as a roster fact', function (mixed $apiKey) {
    // '' is what an unconfigured deploy actually has, since .env.example ships the key empty.
    // Sending unauthenticated would earn a 401 that the sweep would read as "not on the roster".
    $cav = new CavApi(['base_url' => 'https://api.7cav.us', 'api_key' => $apiKey]);

    try {
        $cav->findOnRosterByForumUserId(9);
        $this->fail('Expected a CavRequestException.');
    } catch (CavRequestException $e) {
        expect($e->reason)->toBe(CavFailureReason::MissingCredential)
            ->and($e->reason->isFault())->toBeTrue();
    }
})->with([
    'unset' => [null],
    'empty' => [''],
]);

it('treats "holds no milpac" as the one non-fault outcome', function () {
    // The discriminator that keeps a normal population out of the error channel.
    expect(CavFailureReason::NotOnRoster->isFault())->toBeFalse()
        ->and(CavFailureReason::Unreachable->isFault())->toBeTrue()
        ->and(CavFailureReason::HttpError->isFault())->toBeTrue()
        ->and(CavFailureReason::RecordUnusable->isFault())->toBeTrue();
});

it('gives up on the sweep when the remaining budget cannot afford another call', function () {
    // The safety valve on the synchronous login path. Budget 0 means even the first call is
    // unaffordable, so it fires without burning real seconds — which is why the budget is
    // config-driven rather than a private constant.
    $cav = new CavApi([
        'base_url' => 'https://api.7cav.us',
        'api_key' => 'cav7_test-key',
        'sweep_budget_seconds' => 0.0,
    ]);

    try {
        $cav->findOnRosterByForumUserId(9);
        $this->fail('Expected a CavRequestException.');
    } catch (CavRequestException $e) {
        expect($e->reason)->toBe(CavFailureReason::Unreachable)
            ->and($e->getMessage())->toContain('budget');
    }
});

it('measures the budget against when a call would finish, not when it starts', function () {
    // A budget just under the per-request ceiling cannot afford a call that might run the full
    // ceiling. Checking "have I run out yet?" instead would admit one more whole request timeout,
    // so the sweep would overshoot the stated budget by that much.
    $cav = new CavApi([
        'base_url' => 'https://api.7cav.us',
        'api_key' => 'cav7_test-key',
        'sweep_budget_seconds' => CavConnector::REQUEST_TIMEOUT_SECONDS - 0.5,
    ]);

    expect(fn () => $cav->findOnRosterByForumUserId(9))->toThrow(CavRequestException::class);
});

it('falls back to the default budget when none is configured', function () {
    $cav = new CavApi(['base_url' => 'https://api.7cav.us', 'api_key' => 'cav7_test-key']);

    // Not zero, and not an error: the default has to survive a config block that predates the key.
    expect((fn () => $this->sweepBudgetSeconds)->call($cav))
        ->toBe(CavApi::DEFAULT_SWEEP_BUDGET_SECONDS);
});

it('survives a missing services.cav config block instead of failing at container resolution', function () {
    // `config('services.cav')` is null if the block is absent. A TypeError here would fire during
    // method injection on the callback — before any guard exists — and 500 the login.
    $cav = new CavApi(null);

    expect(fn () => $cav->fetchProfileByCavId(3))->toThrow(CavRequestException::class);
});

it('rejects a whitespace-only base URL', function () {
    $cav = new CavApi(['base_url' => '   ', 'api_key' => 'cav7_test-key']);

    expect(fn () => $cav->fetchProfileByCavId(3))->toThrow(CavRequestException::class);
});

it('rejects a whitespace-only credential', function () {
    $cav = new CavApi(['base_url' => 'https://api.7cav.us', 'api_key' => '   ']);

    try {
        $cav->fetchProfileByCavId(3);
        $this->fail('Expected a CavRequestException.');
    } catch (CavRequestException $e) {
        expect($e->reason)->toBe(CavFailureReason::MissingCredential);
    }
});

it('distinguishes a missing record from an unwilling API', function () {
    // The discriminator that decides whether a stored milpac link is re-resolved. Getting this
    // wrong means a rotated API key is logged as a data-integrity event.
    expect(CavRequestException::profileFetchFailed(77, 404)->indicatesMissingRecord())->toBeTrue()
        ->and(CavRequestException::profileFetchFailed(77, 410)->indicatesMissingRecord())->toBeTrue()
        // A 200 carrying the API's `{code, message}` envelope is also a missing record.
        ->and(CavRequestException::malformedProfile('no user envelope')->indicatesMissingRecord())->toBeTrue()
        ->and(CavRequestException::profileFetchFailed(77, 401)->indicatesMissingRecord())->toBeFalse()
        ->and(CavRequestException::profileFetchFailed(77, 429)->indicatesMissingRecord())->toBeFalse()
        ->and(CavRequestException::profileFetchFailed(77, 503)->indicatesMissingRecord())->toBeFalse()
        ->and(CavRequestException::missingCredential()->indicatesMissingRecord())->toBeFalse();
});

it('carries the HTTP status so callers need not parse the message', function () {
    expect(CavRequestException::profileFetchFailed(77, 404)->status)->toBe(404)
        ->and(CavRequestException::rosterFetchFailed('ROSTER_TYPE_COMBAT', 503)->status)->toBe(503)
        ->and(CavRequestException::notOnRoster(4)->status)->toBeNull();
});

it('authenticates every request with the read-only bearer key', function () {
    // AD-5's "read-only by construction" argument rests entirely on this being the credential in
    // flight, and on it being applied as the connector's default so no request can omit it.
    $connector = new CavConnector('https://api.7cav.us', 'cav7_test-key');
    $pending = $connector->createPendingRequest(new FetchMilpacProfileRequest(3));

    expect($pending->headers()->get('Authorization'))->toBe('Bearer cav7_test-key');
});

it('bounds every request in time, because enrichment sits on the login path', function () {
    $connector = new CavConnector('https://api.7cav.us', 'cav7_test-key');

    expect((int) $connector->getRequestTimeout())->toBe(CavConnector::REQUEST_TIMEOUT_SECONDS)
        ->and((int) $connector->getConnectTimeout())->toBe(CavConnector::CONNECT_TIMEOUT_SECONDS);
});

it('issues only GETs, so nothing can be written back to Cav (AC #4)', function () {
    // Asserted on the request classes themselves rather than on the calls one flow happens to
    // make, so a future write request to this seam fails here even if no flow uses it yet.
    expect((new FetchMilpacProfileRequest(3))->getMethod())->toBe(Method::GET)
        ->and((new FetchRosterRequest(RosterType::Combat))->getMethod())->toBe(Method::GET);
});
