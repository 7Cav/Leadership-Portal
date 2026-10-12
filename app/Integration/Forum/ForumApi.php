<?php

namespace App\Integration\Forum;

use App\Integration\Forum\Data\ForumProfile;
use App\Integration\Forum\Data\ForumThread;
use App\Integration\Forum\Data\ForumThreadPage;
use App\Integration\Forum\Data\ForumThreadSummary;
use App\Integration\Forum\Data\ForumToken;
use App\Integration\Forum\Requests\ExchangeCodeRequest;
use App\Integration\Forum\Requests\FetchProfileRequest;
use App\Integration\Forum\Requests\FetchThreadRequest;
use App\Integration\Forum\Requests\ListForumThreadsRequest;
use Carbon\CarbonImmutable;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Request;
use Saloon\Http\Response;

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
 * used through this seam to read the body of any other part of the forum. A thread id alone cannot be
 * checked against a section before the request, so {@see fetchLoaThread()} compares the section after
 * the fetch and discards the thread if it is outside the configured sections.
 */
class ForumApi
{
    /**
     * `api_key` and `loa_nodes` are only needed for reading leave threads: `loa_nodes` maps each forum
     * section id that holds them to the kind of leave posted there (`loa` or `eloa`).
     *
     * @param  array{client_id: string, client_secret: string, redirect_uri: string, base_url: string, authorize_url: string, scopes: list<string>, api_key?: ?string, loa_nodes?: array<int, string>, paf_node?: ?int}  $config
     */
    public function __construct(private readonly array $config) {}

    /**
     * The forum sections that hold leave threads, with the kind of leave each one holds. Empty until
     * configured, in which case nothing can be read.
     *
     * @return array<int, string> forum section id => `loa` | `eloa`
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
     * refused once the forum answers (an id alone does not say which section it is in) and is never
     * returned, so this stays the one door to leave threads and not a general forum reader.
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
     * The forum section where personnel action forms (PAFs) are filed, or null when it is not configured.
     */
    public function pafNode(): ?int
    {
        $node = $this->config['paf_node'] ?? null;

        return is_int($node) && $node > 0 ? $node : null;
    }

    /**
     * When a PAF thread was created, which is the day the personnel action was filed and so the day an
     * ELOA, or its return, takes effect (a leave report can be posted a day after the PAF). Only the
     * thread's own date is read, never its contents, and only from the configured PAF section.
     *
     * @throws ForumRequestException when no PAF section or key is configured, the forum fails or returns an
     *                               unusable payload, the thread is not visible, or it is not a PAF thread
     */
    public function fetchPafThreadPostedAt(int $threadId): CarbonImmutable
    {
        $pafNode = $this->pafNode() ?? throw ForumRequestException::pafNotConfigured();

        $data = $this->sendForJson(
            $this->withApiKey(new FetchThreadRequest($threadId, withFirstPost: false)),
            ForumRequestException::threadFetchFailed(...),
        );

        $thread = is_array($data['thread'] ?? null) ? ForumThreadSummary::fromArray($data['thread']) : throw ForumRequestException::malformedThread();

        if ($thread->nodeId !== $pafNode) {
            throw ForumRequestException::notAPafThread($thread->nodeId);
        }

        if (! $thread->isVisible()) {
            throw ForumRequestException::threadNotVisible();
        }

        return $thread->postedAt;
    }

    /**
     * The forum's address for a thread, for a row's `source_thread` when the forum did not send one.
     */
    public function threadUrl(int $threadId): string
    {
        return rtrim($this->config['base_url'], '/')."/threads/{$threadId}/";
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
     * @param  callable(int, ?string): ForumRequestException  $onHttpError  given the HTTP status and, when the
     *                                                                      forum sent one, its error code
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
            throw $onHttpError($response->status(), $this->errorCode($response));
        }

        try {
            return $response->json();
        } catch (JsonException $e) {
            throw ForumRequestException::malformedResponse($e);
        }
    }

    /**
     * The forum's own code for a failed call (`errors.0.code`, e.g. `no_api_key_in_request`), so a
     * misconfigured key is diagnosed from the message alone. Null when the body is not the forum's error
     * format. The code is a fixed machine string; the forum's free-text message is never passed on.
     */
    private function errorCode(Response $response): ?string
    {
        try {
            $code = $response->json('errors.0.code');
        } catch (JsonException) {
            return null;
        }

        return is_string($code) && preg_match('/^[a-z0-9_]{1,64}$/', $code) === 1 ? $code : null;
    }

    private function connector(): ForumConnector
    {
        return new ForumConnector($this->config['base_url']);
    }
}
