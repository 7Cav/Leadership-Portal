<?php

namespace App\Integration\Forum;

use App\Integration\Forum\Data\ForumProfile;
use App\Integration\Forum\Data\ForumToken;
use App\Integration\Forum\Requests\ExchangeCodeRequest;
use App\Integration\Forum\Requests\FetchProfileRequest;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Request;

/**
 * The single door out to the 7cav.us forum OAuth2 flow (AD-3). CommandHandlers call
 * this; it owns the connector and requests so no HTTP client is ever instantiated
 * elsewhere. Under the Laravel-idiomatic layer model the seam lives in Integration
 * (there is no separate Services layer) — Deptrac enforces CommandHandler → Integration
 * → External as the only path.
 *
 * The `client_secret` reaches this class only via the injected config (AD-23); it is
 * never read from the DB or the repo, and no user password is ever handled.
 */
class ForumApi
{
    /**
     * @param  array{client_id: string, client_secret: string, redirect_uri: string, base_url: string, authorize_url: string, scopes: list<string>}  $config
     */
    public function __construct(private readonly array $config) {}

    /**
     * Build the browser redirect to the forum authorize endpoint. `$state` is the
     * caller-minted CSRF nonce echoed back on the callback.
     */
    public function authorizeUrl(string $state): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'scope' => implode(' ', $this->config['scopes']),
            'state' => $state,
        ]);

        return $this->config['authorize_url'].'?'.$query;
    }

    public function exchangeCode(string $code): ForumToken
    {
        $request = new ExchangeCodeRequest(
            code: $code,
            clientId: $this->config['client_id'],
            clientSecret: $this->config['client_secret'],
            redirectUri: $this->config['redirect_uri'],
        );

        return ForumToken::fromArray(
            $this->sendForJson($request, ForumRequestException::tokenExchangeFailed(...)),
        );
    }

    public function fetchProfile(ForumToken $token): ForumProfile
    {
        $request = new FetchProfileRequest;
        $request->authenticate(new TokenAuthenticator($token->accessToken));

        return ForumProfile::fromArray(
            $this->sendForJson($request, ForumRequestException::profileFetchFailed(...)),
        );
    }

    /**
     * Send a request and decode its JSON body, translating every forum-side failure
     * mode into a {@see ForumRequestException} so the caller never sees a raw 500
     * (AC #5): connection failures (`FatalRequestException`), HTTP error statuses,
     * and unparseable bodies (`JsonException`) all funnel through one type.
     *
     * @param  callable(int): ForumRequestException  $onHttpError  given the HTTP status
     * @return array<string, mixed>
     */
    private function sendForJson(Request $request, callable $onHttpError): array
    {
        try {
            $response = $this->connector()->send($request);
        } catch (FatalRequestException $e) {
            throw ForumRequestException::unreachable($e);
        }

        if ($response->failed()) {
            throw $onHttpError($response->status());
        }

        try {
            return $response->json();
        } catch (JsonException $e) {
            throw ForumRequestException::malformedResponse($e);
        }
    }

    private function connector(): ForumConnector
    {
        return new ForumConnector($this->config['base_url']);
    }
}
