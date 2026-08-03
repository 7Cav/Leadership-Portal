<?php

use App\Integration\Cav\CavApi;
use App\Integration\Cav\CavFailureReason;
use App\Integration\Cav\CavRequestException;

// Failure modes of the Cav seam that Saloon's MockResponse cannot express, because they happen
// below the response layer. These are the paths the whole degradation design rests on: if any of
// them escaped as something other than a CavRequestException, it would 500 a login.

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
