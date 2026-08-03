<?php

namespace App\Integration\Cav;

use App\Integration\Cav\Data\CavRosterProfile;
use App\Integration\Cav\Data\RosterType;
use App\Integration\Cav\Requests\FetchMilpacProfileRequest;
use App\Integration\Cav\Requests\FetchRosterRequest;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Saloon\Http\Request;
use Throwable;
use UnexpectedValueException;

/**
 * The single door out to the Cav roster/milpac API (AD-3). CommandHandlers call this; it owns
 * the connector and requests so no HTTP client is ever instantiated elsewhere.
 *
 * Read-only by construction: every method issues a GET, and the credential is a static
 * read-only key with no user context, so there is nothing here that could write a roster fact
 * back to Cav (AD-5). The key reaches this class only via the injected config (AD-23) — never
 * the DB, never the repo.
 *
 * Each returned DTO is stamped with the time of its fetch, so provenance is established where
 * the data enters the system rather than guessed at the far end (AD-20, NFR7).
 *
 * **Every failure leaves through {@see CavRequestException}.** That is the contract the login
 * path depends on: enrichment may degrade a session but must never break a login, and it can
 * only promise that if nothing else escapes. Hence the deliberately broad `Throwable` catch
 * around the send — see `sendForJson`.
 */
class CavApi
{
    /**
     * Wall-clock budget for a whole roster sweep. Per-request timeouts bound each call but not
     * the total, and the sweep makes up to three: a login blocking on the sum of them is the
     * failure mode that piles up PHP-FPM workers. Enrichment is optional, so it gives up.
     */
    private const SWEEP_BUDGET_SECONDS = 12.0;

    private ?CavConnector $connector = null;

    /**
     * @param  array{base_url: string|null, api_key: string|null}  $config
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
     * This is the bootstrap for `users.cav_id`: the milpac relation id exists only as a roster
     * map key, so there is no endpoint that maps a forum id to it directly. The match itself is
     * an exact id comparison — the username is never compared (AC #6).
     *
     * A roster that errors does not abort the sweep, because a hit on a *later* roster is a
     * definitive answer regardless: the trooper is on it. Only the negative conclusion needs a
     * complete sweep, so if nothing matched and something failed, the failure is what is
     * raised — never "not on the roster", which would be an unsupported claim.
     *
     * @throws CavRequestException when the trooper is on none of the serving rosters, or the
     *                             sweep could not be completed
     */
    public function findOnRosterByForumUserId(int $forumUserId): CavRosterProfile
    {
        $startedAt = $this->monotonicSeconds();
        $failure = null;

        foreach (RosterType::serving() as $roster) {
            $elapsed = $this->monotonicSeconds() - $startedAt;

            if ($elapsed >= self::SWEEP_BUDGET_SECONDS) {
                throw CavRequestException::sweepBudgetExhausted($forumUserId, self::SWEEP_BUDGET_SECONDS);
            }

            try {
                $payload = $this->sendForJson(
                    new FetchRosterRequest($roster),
                    fn (int $status) => CavRequestException::rosterFetchFailed($roster->value, $status),
                );

                $profile = CavRosterProfile::findInRoster(
                    $payload,
                    $forumUserId,
                    $this->now(),
                    fn (int|string $cavIdKey, string $reason) => Log::warning(
                        'Skipped an unparseable Cav roster entry.',
                        ['roster' => $roster->value, 'cav_id_key' => $cavIdKey, 'reason' => $reason],
                    ),
                );
            } catch (CavRequestException $e) {
                // A record that is unusable *for this trooper* is not something a later roster
                // can answer — they have been found. Anything else, keep sweeping.
                if ($e->reason === CavFailureReason::RecordUnusable) {
                    throw $e;
                }

                $failure ??= $e;

                continue;
            }

            if ($profile !== null) {
                return $profile;
            }
        }

        // Only assert "no milpac" if every roster actually answered. Otherwise the honest
        // answer is that the sweep failed, which is a different thing entirely.
        throw $failure ?? CavRequestException::notOnRoster($forumUserId);
    }

    /**
     * Send a request and decode its JSON body, translating every roster-side failure mode into
     * a {@see CavRequestException} so the caller never sees a raw 500.
     *
     * @param  callable(int): CavRequestException  $onHttpError  given the HTTP status
     * @return array<string, mixed>
     */
    private function sendForJson(Request $request, callable $onHttpError): array
    {
        // Fail before the call rather than send an unauthenticated request and read the 401 as
        // "not on the roster" — a missing key is a deployment fault, not a roster fact. `''` is
        // what an unconfigured deploy actually has, since `.env.example` ships the key empty.
        $apiKey = $this->config['api_key'] ?? null;

        if (! is_string($apiKey) || $apiKey === '') {
            throw CavRequestException::missingCredential();
        }

        $baseUrl = $this->config['base_url'] ?? null;

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            throw CavRequestException::unusableBaseUrl((string) $baseUrl);
        }

        try {
            $response = $this->connector($baseUrl, $apiKey)->send($request);
        } catch (Throwable $e) {
            // Broad on purpose. Saloon's own transport failure is `FatalRequestException`, but
            // a malformed base URL surfaces as a PSR-7 `InvalidArgumentException` from URI
            // construction, and this seam's contract is that *nothing* escapes it — the login
            // path degrades on a Cav failure and must not 500 on an unanticipated one.
            throw CavRequestException::unreachable($e);
        }

        if ($response->failed()) {
            throw $onHttpError($response->status());
        }

        try {
            // Broad on purpose, and this is the load-bearing guard against a 200 whose body is a
            // valid JSON *scalar*. Saloon substitutes `[]` only for an *empty* body; `null`,
            // `123` or `"str"` decode fine and are then assigned to `Response::$decodedJson`,
            // which is typed `array` — so `json()` raises a **TypeError**, not a `JsonException`.
            // Catching only the latter would let it escape and 500 every login for as long as
            // the roster answered that way.
            $payload = $response->json();
        } catch (Throwable $e) {
            throw CavRequestException::malformedResponse($e);
        }

        // Unreachable with Saloon 4 (the throw above happens first) and deliberately kept: it is
        // the backstop if that property type is ever loosened to `mixed`, in which case a scalar
        // would reach this method's `array` return type instead. Not covered by a test, because
        // no input can currently reach it.
        if (! is_array($payload)) {
            throw CavRequestException::malformedResponse(
                new UnexpectedValueException('Cav returned a JSON body that was not an object: '.get_debug_type($payload)),
            );
        }

        return $payload;
    }

    private function connector(string $baseUrl, string $apiKey): CavConnector
    {
        // Memoised so a three-roster sweep reuses one connector, and with it Guzzle's
        // connection pool, instead of building three.
        return $this->connector ??= new CavConnector($baseUrl, $apiKey);
    }

    /**
     * Carbon rather than a bare `new DateTimeImmutable`, so `CarbonImmutable::setTestNow()` can
     * pin the provenance timestamp a test asserts on.
     */
    private function now(): DateTimeImmutable
    {
        return CarbonImmutable::now()->toDateTimeImmutable();
    }

    /**
     * Elapsed-time source for the sweep budget. Deliberately *not* Carbon: the budget must
     * measure real time even in a test that has frozen the clock for provenance assertions.
     */
    private function monotonicSeconds(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
