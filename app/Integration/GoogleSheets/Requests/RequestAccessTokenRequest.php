<?php

namespace App\Integration\GoogleSheets\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasFormBody;

/**
 * Trades the service account's signed JWT for an access token: `POST https://oauth2.googleapis.com/token` with the
 * JWT-bearer grant (RFC 7523).
 */
class RequestAccessTokenRequest extends Request implements HasBody
{
    use HasFormBody;

    protected Method $method = Method::POST;

    public function __construct(#[\SensitiveParameter] private readonly string $assertion) {}

    public function resolveEndpoint(): string
    {
        return '/token';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultBody(): array
    {
        return ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $this->assertion];
    }
}
