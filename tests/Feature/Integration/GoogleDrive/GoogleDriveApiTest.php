<?php

use App\Integration\GoogleDrive\GoogleDriveApi;
use App\Integration\GoogleDrive\GoogleDriveConnector;
use App\Integration\GoogleDrive\GoogleDriveUnavailable;
use App\Integration\GoogleDrive\Requests\RequestAccessTokenRequest;
use App\Integration\GoogleDrive\Requests\StartUploadRequest;
use App\Integration\GoogleDrive\Requests\UploadContentRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Http\Request;

/**
 * #381 — the Google Drive upload seam. Every call is faked with Saloon's MockClient; nothing here reaches Google. The
 * key is generated for the test, never a real one.
 */
function driveKeyPair(): array
{
    static $pair = null;

    if ($pair === null) {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $pair = [$private, openssl_pkey_get_details($key)['key']];
    }

    return $pair;
}

function driveConfig(array $overrides = []): array
{
    return [
        'service_account_email' => 'portal@cav-test.iam.gserviceaccount.com',
        // As the env holds it: one line, its breaks written as \n.
        'private_key' => str_replace("\n", '\n', driveKeyPair()[0]),
        'folder_id' => 'folder-123',
        ...$overrides,
    ];
}

function driveMocks(array $overrides = []): MockClient
{
    return MockClient::global([
        RequestAccessTokenRequest::class => MockResponse::make(['access_token' => 'ya29.test-token', 'expires_in' => 3599, 'token_type' => 'Bearer']),
        StartUploadRequest::class => MockResponse::make('', 200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=ABC-123_x']),
        UploadContentRequest::class => MockResponse::make(['id' => 'drive-file-9']),
        ...$overrides,
    ]);
}

it('is configured only when the email, the key and the folder are all set', function () {
    expect((new GoogleDriveApi(driveConfig()))->isConfigured())->toBeTrue()
        ->and((new GoogleDriveApi(driveConfig(['folder_id' => ' '])))->isConfigured())->toBeFalse()
        ->and((new GoogleDriveApi(driveConfig(['private_key' => null])))->isConfigured())->toBeFalse()
        ->and((new GoogleDriveApi(driveConfig(['service_account_email' => ''])))->isConfigured())->toBeFalse()
        ->and((new GoogleDriveApi(null))->isConfigured())->toBeFalse()
        // The test env pins all three empty, so the bound seam is never configured in a test.
        ->and(app(GoogleDriveApi::class)->isConfigured())->toBeFalse();
});

it('signs a service account assertion, trades it for a token, and uploads the bytes into the folder', function () {
    $mock = driveMocks();

    $id = (new GoogleDriveApi(driveConfig()))->upload('DCS 2026-10-20 Brandt.L.pdf', '%PDF-1.4 body');

    expect($id)->toBe('drive-file-9');

    $mock->assertSent(function (Request $request, $response) {
        if (! $request instanceof RequestAccessTokenRequest) {
            return false;
        }
        $body = $response->getPendingRequest()->body()->all();
        [$header, $claims, $signature] = explode('.', $body['assertion']);
        $decode = fn (string $part) => json_decode(base64_decode(strtr($part, '-_', '+/')), true);
        $verified = openssl_verify("{$header}.{$claims}", base64_decode(strtr($signature, '-_', '+/').str_repeat('=', (4 - strlen($signature) % 4) % 4)), driveKeyPair()[1], OPENSSL_ALGO_SHA256);

        return $body['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && $decode($header) === ['alg' => 'RS256', 'typ' => 'JWT']
            && $decode($claims)['iss'] === 'portal@cav-test.iam.gserviceaccount.com'
            && $decode($claims)['aud'] === 'https://oauth2.googleapis.com/token'
            && $decode($claims)['scope'] === GoogleDriveApi::SCOPE
            && $decode($claims)['exp'] - $decode($claims)['iat'] === 3600
            && $verified === 1
            && (string) $response->getPendingRequest()->getUri() === 'https://oauth2.googleapis.com/token';
    });

    $mock->assertSent(function (Request $request, $response) {
        $pending = $response->getPendingRequest();

        return $request instanceof StartUploadRequest
            && $pending->body()->all() === ['name' => 'DCS 2026-10-20 Brandt.L.pdf', 'parents' => ['folder-123'], 'mimeType' => 'application/pdf']
            && $pending->headers()->get('Authorization') === 'Bearer ya29.test-token'
            && $pending->query()->get('uploadType') === 'resumable';
    });

    $mock->assertSent(function (Request $request, $response) {
        $pending = $response->getPendingRequest();

        return $request instanceof UploadContentRequest
            && (string) $pending->body() === '%PDF-1.4 body'
            && $pending->query()->get('upload_id') === 'ABC-123_x'
            && $pending->getUri()->getHost() === 'www.googleapis.com'
            && $pending->getUri()->getPath() === '/upload/drive/v3/files';
    });
});

it('fails as unavailable, naming no credential, on every way Google can let it down', function (array $mocks, string $message) {
    driveMocks($mocks);

    try {
        (new GoogleDriveApi(driveConfig()))->upload('x.pdf', '%PDF-1.4 secret-body');
        $this->fail('expected the upload to fail');
    } catch (GoogleDriveUnavailable $e) {
        expect($e->getMessage())->toContain($message)
            ->not->toContain('PRIVATE KEY')
            ->not->toContain('ya29.test-token')
            ->not->toContain('secret-body');
    }
})->with([
    'token refused' => [[RequestAccessTokenRequest::class => MockResponse::make(['error' => 'invalid_grant'], 400)], 'HTTP 400 to the token request'],
    'token missing' => [[RequestAccessTokenRequest::class => MockResponse::make(['token_type' => 'Bearer'])], 'no access token'],
    'folder not shared' => [[StartUploadRequest::class => MockResponse::make(['error' => 'notFound'], 404)], 'HTTP 404 to the upload start'],
    'session on another host' => [[StartUploadRequest::class => MockResponse::make('', 200, ['Location' => 'https://evil.example/upload/drive/v3/files?upload_id=A'])], 'no upload session'],
    'no session' => [[StartUploadRequest::class => MockResponse::make('', 200)], 'no upload session'],
    'odd upload id' => [[StartUploadRequest::class => MockResponse::make('', 200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=a/../b'])], 'no usable upload id'],
    'upload refused' => [[UploadContentRequest::class => MockResponse::make('', 503)], 'HTTP 503 to the upload'],
    'no file id' => [[UploadContentRequest::class => MockResponse::make(['kind' => 'drive#file'])], 'no file id'],
    'not json' => [[UploadContentRequest::class => MockResponse::make('<html>', 200)], 'not JSON'],
    'a redirect' => [[RequestAccessTokenRequest::class => MockResponse::make('', 302, ['Location' => 'https://evil.example/'])], 'HTTP 302'],
]);

it('refuses to run unconfigured, or with a key it cannot read, before anything is sent', function (array $config, string $message) {
    $mock = driveMocks();

    expect(fn () => (new GoogleDriveApi($config))->upload('x.pdf', '%PDF-'))->toThrow(GoogleDriveUnavailable::class, $message);
    $mock->assertNothingSent();
})->with([
    'unconfigured' => [['folder_id' => 'f'], 'must all be set'],
    'not a key' => [['service_account_email' => 'a@b.c', 'private_key' => 'not a key', 'folder_id' => 'f'], 'not a readable private key'],
]);

it('lets the Drive connector send nothing but an upload', function () {
    $mock = MockClient::global([MockResponse::make(['id' => 'x'])]);
    $read = new class extends Request
    {
        protected Method $method = Method::GET;

        public function resolveEndpoint(): string
        {
            return '/drive/v3/files';
        }
    };

    expect(fn () => (new GoogleDriveConnector('token'))->send($read))->toThrow(GoogleDriveUnavailable::class, 'refused before sending');
    $mock->assertNothingSent();
});

it('keeps the key out of a stack trace', function () {
    driveMocks([RequestAccessTokenRequest::class => fn (PendingRequest $pending) => throw new RuntimeException('boom')]);

    try {
        (new GoogleDriveApi(driveConfig()))->upload('x.pdf', '%PDF-1.4 secret-body');
    } catch (GoogleDriveUnavailable $e) {
        expect((string) $e)->not->toContain('BEGIN PRIVATE KEY')->not->toContain('secret-body');

        return;
    }

    $this->fail('expected the upload to fail');
});
