<?php

namespace App\Integration\Forum;

use App\Integration\Forum\Data\ForumProfile;
use App\Integration\Forum\Data\ForumThread;
use App\Integration\Forum\Data\ForumThreadPage;
use App\Integration\Forum\Data\ForumToken;
use App\Integration\Forum\Requests\ExchangeCodeRequest;
use App\Integration\Forum\Requests\FetchProfileRequest;
use App\Integration\Forum\Requests\FetchThreadRequest;
use App\Integration\Forum\Requests\ListForumThreadsRequest;
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
 *
 * It is also the only read path to the forum's leave threads (Story 4.1): {@see listLoaThreads()} and
 * {@see fetchLoaThread()}. Those use a forum API key (`api_key`, env only, AD-23) rather than a user's
 * OAuth token, are read-only, and only ever read the sections named in `loa_nodes`, so the key cannot be
 * used through this seam to read any other part of the forum.
 */
class ForumApi
{
    /**
     * `api_key` and `loa_nodes` are only needed for reading leave threads: `loa_nodes` maps each forum
     * section id that holds them to the kind of leave posted there (`loa`, `eloa` or `reserve`).
     *
     * @param  array{client_id: string, client_secret: string, redirect_uri: string, base_url: string, authorize_url: string, scopes: list<string>, api_key?: ?string, loa_nodes?: array<int, string>}  $config
     */
    public function __construct(private readonly array $config) {}

    /**
     * The forum sections that hold leave threads, with the kind of leave each one holds. Empty until
     * configured, in which case nothing can be read.
     *
     * @return array<int, string> forum section id => `loa` | `eloa` | `reserve`
     */
    public function loaNodes(): array
    {
        return $this->config['loa_nodes'] ?? [];
    }

    /**
     * One page of a leave section's threads, without their bodies. Only a configured leave section may
     * be listed; the key is checked before any request is made.
     *
     * @throws ForumRequestException when the section is not a configured one, no API key is set, or the
     *                               forum fails or returns an unusable payload
     */
    public function listLoaThreads(int $nodeId, int $page = 1): ForumThreadPage
    {
        if (! array_key_exists($nodeId, $this->loaNodes())) {
            throw ForumRequestException::notALoaSection($nodeId);
        }

        return ForumThreadPage::fromArray(
            $this->sendForJson($this->withApiKey(new ListForumThreadsRequest($nodeId, $page)), ForumRequestException::threadListFailed(...)),
        );
    }

    /**
     * One thread with the body of its first post. A thread outside the configured leave sections is
     * refused even though the key could read it, so this stays the one door to leave threads and
     * not a general forum reader.
     *
     * @throws ForumRequestException when no API key is set, the forum fails or returns an unusable
     *                               payload, or the thread is not in a configured leave section
     */
    public function fetchLoaThread(int $threadId): ForumThread
    {
        $thread = ForumThread::fromArray(
            $this->sendForJson($this->withApiKey(new FetchThreadRequest($threadId)), ForumRequestException::threadFetchFailed(...)),
        );

        if (! array_key_exists($thread->summary->nodeId, $this->loaNodes())) {
            throw ForumRequestException::notALoaSection($thread->summary->nodeId);
        }

        return $thread;
    }

    /**
     * Applies the forum API key as the request's credential. Checked before sending, so a missing key
     * fails here with a clear message instead of as an unauthorized response.
     */
    private function withApiKey(Request $request): Request
    {
        $key = $this->config['api_key'] ?? null;

        if (! is_string($key) || $key === '') {
            throw ForumRequestException::apiKeyMissing();
        }

        $request->headers()->add('XF-Api-Key', $key);

        return $request;
    }

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
