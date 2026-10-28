<?php

namespace App\Integration\GoogleDrive;

use App\Integration\RefusesRedirects;
use Saloon\Enums\Method;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * The Google OAuth token endpoint, for the service account's signed assertion (#381, AD-3). Reached only from
 * {@see GoogleDriveApi}. The host is fixed here, never read from the service account's key file, so the signed
 * assertion can only ever go to Google. A boot hook refuses anything but `POST /token`; redirects are not followed.
 */
class GoogleOAuthConnector extends Connector
{
    use AcceptsJson, HasTimeout, RefusesRedirects;

    public const BASE_URL = 'https://oauth2.googleapis.com';

    protected int $connectTimeout = 5;

    protected int $requestTimeout = 15;

    public function resolveBaseUrl(): string
    {
        return self::BASE_URL;
    }

    public function boot(PendingRequest $pendingRequest): void
    {
        $uri = $pendingRequest->getUri();

        if ($pendingRequest->getMethod() !== Method::POST || $uri->getScheme() !== 'https' || $uri->getHost() !== 'oauth2.googleapis.com' || $uri->getPath() !== '/token') {
            throw GoogleDriveUnavailable::refused('only POST /token goes to the token endpoint');
        }
    }
}
