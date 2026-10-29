<?php

namespace App\Integration\GoogleSheets;

use App\Integration\RefusesRedirects;
use Saloon\Enums\Method;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * The Google Sheets API (AD-3), reached only from {@see GoogleSheetsApi} with a short-lived service account token.
 * Append-only: a boot hook refuses, before anything is sent, anything but a POST to
 * `/v4/spreadsheets/{id}/values/{range}:append`, so no request built here can read, clear or delete anything in the
 * sheet. Redirects are not followed ({@see RefusesRedirects}).
 */
class GoogleSheetsConnector extends Connector
{
    use AcceptsJson, HasTimeout, RefusesRedirects;

    public const BASE_URL = 'https://sheets.googleapis.com';

    protected int $connectTimeout = 5;

    protected int $requestTimeout = 30;

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

        if ($pendingRequest->getMethod() !== Method::POST
            || $uri->getScheme() !== 'https'
            || $uri->getHost() !== 'sheets.googleapis.com'
            || preg_match('#^/v4/spreadsheets/[^/]+/values/[^/]+:append$#', $uri->getPath()) !== 1) {
            throw GoogleSheetsUnavailable::refused('only row appends are sent');
        }
    }
}
