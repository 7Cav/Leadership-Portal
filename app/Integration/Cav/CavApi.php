<?php

namespace App\Integration\Cav;

use App\Integration\Cav\Data\CavRosterProfile;
use App\Integration\Cav\Requests\FetchMilpacProfileRequest;
use App\Integration\Cav\Requests\FetchRosterRequest;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Request;

/**
 * The single door out to the Cav roster/milpac API (AD-3). CommandHandlers call this; it
 * owns the connector and requests so no HTTP client is ever instantiated elsewhere.
 *
 * Read-only by construction: every method issues a GET, and the credential is a static
 * read-only key with no user context, so there is nothing here that could write a roster
 * fact back to Cav (AD-5). The key reaches this class only via the injected config
 * (AD-23) — never the DB, never the repo.
 *
 * Each returned DTO is stamped with the time of its fetch, so provenance is established
 * where the data enters the system rather than guessed at the far end (AD-20, NFR7).
 */
class CavApi
{
    /**
     * The rosters a serving trooper can appear on, in descending likelihood — the sweep
     * stops at the first hit, so the common case costs one call. `WALL_OF_HONOR`,
     * `ARLINGTON` and `PAST_MEMBERS` are deliberately absent: nobody who can sign in to
     * the portal is on them.
     */
    private const SERVING_ROSTERS = [
        'ROSTER_TYPE_COMBAT',
        'ROSTER_TYPE_RESERVE',
        'ROSTER_TYPE_ELOA',
    ];

    /**
     * @param  array{base_url: string, api_key: string|null}  $config
     */
    public function __construct(private readonly array $config) {}

    /**
     * Fetch a known trooper's roster context by their milpac relation id.
     */
    public function fetchProfileByCavId(int $cavId): CavRosterProfile
    {
        $payload = $this->sendForJson(
            new FetchMilpacProfileRequest($cavId),
            fn (int $status) => CavRequestException::profileFetchFailed($cavId, $status),
        );

        return CavRosterProfile::fromPayload($cavId, $payload, $this->now());
    }

    /**
     * Resolve a trooper from their forum user id by sweeping the serving rosters.
     *
     * This is the bootstrap for `users.cav_id`: the milpac relation id exists only as a
     * roster map key, so there is no endpoint that maps a forum id to it directly. The
     * match itself is an exact id comparison — the username is never compared (AC #6).
     *
     * @throws CavRequestException when the trooper is on none of the serving rosters
     */
    public function findOnRosterByForumUserId(int $forumUserId): CavRosterProfile
    {
        foreach (self::SERVING_ROSTERS as $roster) {
            $payload = $this->sendForJson(
                new FetchRosterRequest($roster),
                fn (int $status) => CavRequestException::rosterFetchFailed($roster, $status),
            );

            $profile = CavRosterProfile::findInRoster($payload, $forumUserId, $this->now());

            if ($profile !== null) {
                return $profile;
            }
        }

        // Not an error condition of the API — a real, expected outcome for a forum member
        // who holds no milpac. The caller decides what an unenriched session looks like.
        throw CavRequestException::notOnRoster($forumUserId);
    }

    /**
     * Send a request and decode its JSON body, translating every roster-side failure mode
     * into a {@see CavRequestException} so the caller never sees a raw 500: connection
     * failures (`FatalRequestException`), HTTP error statuses, and unparseable bodies
     * (`JsonException`) all funnel through one type.
     *
     * @param  callable(int): CavRequestException  $onHttpError  given the HTTP status
     * @return array<string, mixed>
     */
    private function sendForJson(Request $request, callable $onHttpError): array
    {
        // Fail before the call rather than send an unauthenticated request and read a 401
        // as "not on the roster" — a missing key is a deployment fault, not a roster fact.
        if (($this->config['api_key'] ?? null) === null || $this->config['api_key'] === '') {
            throw CavRequestException::missingCredential();
        }

        try {
            $response = $this->connector()->send($request);
        } catch (FatalRequestException $e) {
            throw CavRequestException::unreachable($e);
        }

        if ($response->failed()) {
            throw $onHttpError($response->status());
        }

        try {
            return $response->json();
        } catch (JsonException $e) {
            throw CavRequestException::malformedResponse($e);
        }
    }

    private function connector(): CavConnector
    {
        return new CavConnector($this->config['base_url'], (string) $this->config['api_key']);
    }

    /**
     * Carbon rather than a bare `new DateTimeImmutable`, so `CarbonImmutable::setTestNow()`
     * can pin the provenance timestamp a test asserts on.
     */
    private function now(): DateTimeImmutable
    {
        return CarbonImmutable::now()->toDateTimeImmutable();
    }
}
