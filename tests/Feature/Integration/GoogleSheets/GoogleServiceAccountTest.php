<?php

use App\Integration\GoogleSheets\GoogleServiceAccount;
use App\Integration\GoogleSheets\GoogleSheetsUnavailable;
use App\Integration\GoogleSheets\Requests\RequestAccessTokenRequest;
use App\Integration\GoogleSheets\SheetsClient;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

/**
 * The Google service account the reports Sheet push signs in as (#565 moved it here when the Drive copy was removed).
 * Every call is faked with Saloon's MockClient; nothing here reaches Google. The key is generated for the test.
 */
function serviceAccountKeyPair(): array
{
    static $pair = null;

    if ($pair === null) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $pair = [$private, openssl_pkey_get_details($key)['key']];
    }

    return $pair;
}

function serviceAccountConfig(array $overrides = []): array
{
    return [
        'service_account_email' => 'portal@cav-test.iam.gserviceaccount.com',
        // As the env holds it: one line, its breaks written as \n.
        'private_key' => str_replace("\n", '\n', serviceAccountKeyPair()[0]),
        ...$overrides,
    ];
}

afterEach(fn () => MockClient::destroyGlobal());

it('is configured only when the email and the key are both set', function () {
    expect((new GoogleServiceAccount(serviceAccountConfig()))->isConfigured())->toBeTrue()
        ->and((new GoogleServiceAccount(serviceAccountConfig(['private_key' => null])))->isConfigured())->toBeFalse()
        ->and((new GoogleServiceAccount(serviceAccountConfig(['service_account_email' => ' '])))->isConfigured())->toBeFalse()
        ->and((new GoogleServiceAccount(null))->isConfigured())->toBeFalse()
        // The test env sets neither key, so the bound Sheets push is never configured in a test.
        ->and(app(SheetsClient::class)->isConfigured())->toBeFalse();
});

it('signs an assertion for the Sheets scope alone and trades it for a token at the fixed endpoint', function () {
    $mock = MockClient::global([RequestAccessTokenRequest::class => MockResponse::make(['access_token' => 'ya29.test-token'])]);

    expect((new GoogleServiceAccount(serviceAccountConfig()))->accessToken())->toBe('ya29.test-token');

    $mock->assertSent(function ($request, $response) {
        $pending = $response->getPendingRequest();
        [$header, $claims, $signature] = explode('.', $pending->body()->all()['assertion']);
        $claims = json_decode(base64_decode(strtr($claims, '-_', '+/')), true);
        $verified = openssl_verify("{$header}.".explode('.', $pending->body()->all()['assertion'])[1], base64_decode(strtr($signature, '-_', '+/')), serviceAccountKeyPair()[1], OPENSSL_ALGO_SHA256);

        return $pending->getUri()->getHost() === 'oauth2.googleapis.com'
            && $claims['scope'] === 'https://www.googleapis.com/auth/spreadsheets'
            && $claims['iss'] === 'portal@cav-test.iam.gserviceaccount.com'
            && $verified === 1;
    });
});

it('fails as unavailable, naming no credential, on every way Google can let it down', function (MockResponse|Closure $answer, string $message) {
    MockClient::global([RequestAccessTokenRequest::class => $answer]);

    try {
        (new GoogleServiceAccount(serviceAccountConfig()))->accessToken();
        $this->fail('expected the token request to fail');
    } catch (GoogleSheetsUnavailable $e) {
        expect($e->getMessage())->toContain($message)->not->toContain('PRIVATE KEY');
        expect((string) $e)->not->toContain('BEGIN PRIVATE KEY');
    }
})->with([
    'refused' => [MockResponse::make(['error' => 'invalid_grant'], 400), 'HTTP 400 to the token request'],
    'no token' => [MockResponse::make(['token_type' => 'Bearer']), 'no access token'],
    'a redirect' => [MockResponse::make('', 302, ['Location' => 'https://evil.example/']), 'HTTP 302'],
    // Pest calls a closure in a dataset to resolve it, so the throwing answer is wrapped once more.
    'unreachable' => [fn () => fn (PendingRequest $pending) => throw new RuntimeException('boom'), 'could not be reached'],
]);

it('refuses to run unconfigured, or with a key it cannot read, before anything is sent', function (array $config, string $message) {
    $mock = MockClient::global([RequestAccessTokenRequest::class => MockResponse::make(['access_token' => 'x'])]);

    expect(fn () => (new GoogleServiceAccount($config))->accessToken())->toThrow(GoogleSheetsUnavailable::class, $message);
    $mock->assertNothingSent();
})->with([
    'unconfigured' => [['service_account_email' => 'a@b.c'], 'must both be set'],
    'not a key' => [['service_account_email' => 'a@b.c', 'private_key' => 'not a key'], 'not a readable private key'],
]);
