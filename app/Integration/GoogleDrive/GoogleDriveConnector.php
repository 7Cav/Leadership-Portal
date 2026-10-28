<?php

namespace App\Integration\GoogleDrive;

use App\Integration\RefusesRedirects;
use Saloon\Enums\Method;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * The Google Drive upload API (#381, AD-3), reached only from {@see GoogleDriveApi} with a short-lived access token the
 * service account was just issued. **Upload-only**: a boot hook refuses, before anything is sent, any method other
 * than POST (start an upload) or PUT (send its bytes) and any path other than `/upload/drive/v3/files`, so no request
 * built here can list, read, share or delete anything on the Drive. Redirects are not followed ({@see RefusesRedirects}).
 */
class GoogleDriveConnector extends Connector
{
    use AcceptsJson, HasTimeout, RefusesRedirects;

    public const BASE_URL = 'https://www.googleapis.com';

    public const UPLOAD_PATH = '/upload/drive/v3/files';

    protected int $connectTimeout = 5;

    /** A 10 MB document on a slow link. */
    protected int $requestTimeout = 60;

    public function __construct(#[\SensitiveParameter] private readonly string $accessToken) {}

    public function resolveBaseUrl(): string
    {
        return self::BASE_URL;
    }

    protected function defaultAuth(): TokenAuthenticator
    {
        return new TokenAuthenticator($this->accessToken);
    }

    public function boot(PendingRequest $pendingRequest): void
    {
        $uri = $pendingRequest->getUri();

        if (! in_array($pendingRequest->getMethod(), [Method::POST, Method::PUT], true)
            || $uri->getScheme() !== 'https'
            || $uri->getHost() !== 'www.googleapis.com'
            || $uri->getPath() !== self::UPLOAD_PATH) {
            throw GoogleDriveUnavailable::refused('only uploads to '.self::UPLOAD_PATH.' are sent');
        }
    }
}
