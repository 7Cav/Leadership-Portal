<?php

namespace App\Integration\Cav;

use App\Integration\Cav\Data\CavPositionGroup;
use App\Integration\Cav\Data\CavRosterEntry;
use App\Integration\Cav\Data\CavRosterProfile;
use App\Integration\Cav\Data\MilpacId;
use App\Integration\Cav\Data\PositionGroupsFetchResult;
use App\Integration\Cav\Data\RosterFetchResult;
use App\Integration\Cav\Data\RosterRowError;
use App\Integration\Cav\Data\RosterType;
use App\Integration\Cav\Requests\FetchMilpacProfileRequest;
use App\Integration\Cav\Requests\FetchPositionGroupsRequest;
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
 * only promise that if nothing else escapes. Hence the deliberately broad `Throwable` catches.
 */
class CavApi
{
    /**
     * Default wall-clock budget for a whole roster sweep. Per-request timeouts bound each call
     * but not the total, and the sweep makes up to three: a login blocking on the sum of them is
     * the failure mode that piles up PHP-FPM workers. Enrichment is optional, so it gives up.
     */
    public const DEFAULT_SWEEP_BUDGET_SECONDS = 12.0;

    private ?CavConnector $connector = null;

    private readonly float $sweepBudgetSeconds;

    /**
     * `?array` rather than `array`: `config('services.cav')` is null if the block is missing, and
     * a TypeError here would fire during container resolution — before any caller's guard exists,
     * so it would 500 the login instead of degrading the session.
     *
     * @param  array{base_url?: string|null, api_key?: string|null, sweep_budget_seconds?: float|int|null}|null  $config
     */
    public function __construct(private readonly ?array $config = [])
    {
        // Configurable so the safety valve is reachable in a test without burning real seconds.
        $budget = $config['sweep_budget_seconds'] ?? null;

        $this->sweepBudgetSeconds = is_numeric($budget)
            ? (float) $budget
            : self::DEFAULT_SWEEP_BUDGET_SECONDS;
    }

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
     * complete sweep — so "not on the roster" is claimed **only** when every roster answered and
     * every row in them parsed. Anything less is raised as the failure it was.
     *
     * @throws CavRequestException when the trooper is on none of the serving rosters, or the
     *                             sweep could not be completed
     */
    public function findOnRosterByForumUserId(int $forumUserId): CavRosterProfile
    {
        $startedAt = $this->monotonicSeconds();
        $failure = null;
        $skipped = 0;

        foreach (RosterType::serving() as $roster) {
            // Measured against the time a call would *finish*, not the time it starts. A check
            // that only asks "have I run out yet?" always admits one more full request timeout,
            // so the budget would overshoot by that much; skipping a call we cannot afford to
            // complete is what actually bounds the sweep.
            $remaining = $this->sweepBudgetSeconds - ($this->monotonicSeconds() - $startedAt);

            if ($remaining < $this->requestCeilingSeconds()) {
                // Prefer a failure we actually diagnosed over the generic timeout.
                throw $failure ?? CavRequestException::sweepBudgetExhausted($forumUserId, $this->sweepBudgetSeconds);
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
                    function (int|string $cavIdKey, string $reason) use ($roster, $forumUserId, &$skipped) {
                        $skipped++;

                        Log::warning('Skipped an unparseable Cav roster entry.', [
                            'roster' => $roster->value,
                            'cav_id_key' => $cavIdKey,
                            // Without this the warning cannot be tied to the login it explains.
                            'sought_forum_user_id' => $forumUserId,
                            'reason' => $reason,
                        ]);
                    },
                );
            } catch (CavRequestException $e) {
                // Logged here, not only at the end: `$failure` keeps the *first* failure and a
                // later match discards it entirely, so without this a Combat roster erroring for
                // a week would be invisible for every trooper who happens to be on Reserve.
                Log::warning('A Cav roster could not be read; continuing the sweep.', [
                    'roster' => $roster->value,
                    'reason' => $e->reason->value,
                    'status' => $e->status,
                    'sought_forum_user_id' => $forumUserId,
                    'message' => $e->getMessage(),
                ]);

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

        if ($failure !== null) {
            throw $failure;
        }

        // Every roster answered, but rows in them were unparseable — so a roster carrying this
        // trooper in a broken row cannot be ruled out, and absence is not established.
        if ($skipped > 0) {
            throw CavRequestException::rosterIncomplete($forumUserId, $skipped);
        }

        throw CavRequestException::notOnRoster($forumUserId);
    }

    /**
     * Fetch the full active roster — every serving roster (Combat, Reserve, ELOA), merged into
     * one collection (story 2.1).
     *
     * Unlike {@see findOnRosterByForumUserId}, a roster that fails here aborts the whole sweep
     * immediately: that method can afford to keep looking because a hit on a later roster is a
     * definitive answer regardless, but there is no such fallback for "the full roster" — a
     * roster this call could not read makes the result incomplete, not merely inconclusive, so
     * AC #4/#5 require the caller see that failure rather than a silently short roster.
     *
     * A single unparseable *row*, in contrast, is collected rather than thrown (AC #6): a sweep
     * of ~800 troopers reliably carries a handful of stale entries, and aborting the whole fetch
     * over one would mean the sync never completes.
     *
     * @throws CavRequestException when any roster call fails or returns an unusable shape
     */
    public function fetchRoster(): RosterFetchResult
    {
        $fetchedAt = $this->now();
        $troopers = [];
        $errors = [];
        $emptyRosters = [];

        foreach (RosterType::serving() as $roster) {
            $payload = $this->sendForJson(
                new FetchRosterRequest($roster),
                fn (int $status) => CavRequestException::rosterFetchFailed($roster->value, $status),
            );

            $profiles = $payload['profiles'] ?? null;

            if (! is_array($profiles)) {
                throw CavRequestException::malformedRoster('it carried no `profiles` map');
            }

            // A JSON *list* would pass `is_array` while its keys were 0,1,2… — array offsets
            // that look like valid milpac ids. See CavRosterProfile::findInRoster for the full
            // rationale; the same fabrication risk applies to a full-roster read.
            if ($profiles !== [] && array_is_list($profiles)) {
                throw CavRequestException::malformedRoster('its `profiles` field was a list, not a map keyed by milpac id');
            }

            // Reported rather than refused. Unlike an empty position-groups list (always broken),
            // a serving roster can be empty for real, so whether this one is believable depends
            // on who the cache last saw on it, which only the sync handler knows.
            if ($profiles === []) {
                $emptyRosters[] = $roster;
            }

            foreach ($profiles as $key => $entry) {
                try {
                    $cavId = $this->parseRosterKey($key);

                    if (! is_array($entry)) {
                        throw CavRequestException::malformedProfile('the entry was not an object');
                    }

                    $troopers[] = CavRosterEntry::fromPayload($cavId, $entry, $fetchedAt);
                } catch (CavRequestException $e) {
                    Log::warning('Skipped an unparseable Cav roster entry during a full-roster fetch.', [
                        'roster' => $roster->value,
                        'cav_id_key' => $key,
                        'reason' => $e->getMessage(),
                    ]);

                    $errors[] = new RosterRowError($key, $e->getMessage());
                }
            }
        }

        return new RosterFetchResult($troopers, $errors, $emptyRosters);
    }

    /**
     * Fetch every milpac position group with its positions (story 2.9), sorted into Cav's own
     * display order.
     *
     * All-or-nothing, unlike {@see fetchRoster}'s per-row tolerance: the caller builds the chain
     * of command from this, and an org tree imported from a silently partial structure would be
     * wrong in ways nobody would notice — so any unusable group or position fails the whole
     * fetch. An empty `groups` list is treated as unusable too: Cav always publishes its
     * holding groups, so an empty list means the payload is broken, not that the regiment is.
     *
     * @throws CavRequestException when the call fails or the payload is unusable
     */
    public function fetchPositionGroups(): PositionGroupsFetchResult
    {
        $fetchedAt = $this->now();

        $payload = $this->sendForJson(
            new FetchPositionGroupsRequest,
            fn (int $status) => CavRequestException::positionGroupsFetchFailed($status),
        );

        $groups = $payload['groups'] ?? null;

        if (! is_array($groups) || ! array_is_list($groups) || $groups === []) {
            throw CavRequestException::malformedPositionGroups('it carried no `groups` list');
        }

        $parsed = array_map(function (mixed $group) {
            if (! is_array($group)) {
                throw CavRequestException::malformedPositionGroups('a group was not an object');
            }

            // Named in the error, since a group with no usable id has nothing else to name it by.
            if (CavPositionGroup::positiveInteger($group['groupId'] ?? null) === null) {
                throw CavRequestException::malformedPositionGroups(sprintf(
                    "group '%s' carried no usable `groupId`",
                    is_string($group['groupTitle'] ?? null) ? $group['groupTitle'] : '(untitled)',
                ));
            }

            return CavPositionGroup::fromPayload($group);
        }, $groups);

        usort($parsed, fn (CavPositionGroup $a, CavPositionGroup $b) => $a->displayOrder <=> $b->displayOrder);

        return new PositionGroupsFetchResult($parsed, $fetchedAt);
    }

    /**
     * Parse a roster map key via the shared {@see MilpacId::parse()} predicate, reported as a
     * collectible per-row error here rather than an aborting exception, since an unparseable key
     * on a full-roster read costs the caller one row, not the whole roster.
     */
    private function parseRosterKey(int|string $key): int
    {
        return MilpacId::parse($key)
            ?? throw CavRequestException::malformedProfile("cav id key '{$key}' is not a positive integer");
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

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw CavRequestException::missingCredential();
        }

        $baseUrl = $this->config['base_url'] ?? null;

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            throw CavRequestException::unusableBaseUrl(is_string($baseUrl) ? $baseUrl : get_debug_type($baseUrl));
        }

        try {
            $response = $this->connector(trim($baseUrl), $apiKey)->send($request);
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

    private function connector(string $baseUrl, #[\SensitiveParameter] string $apiKey): CavConnector
    {
        // Memoised so a three-roster sweep reuses one connector, and with it Guzzle's
        // connection pool, instead of building three.
        return $this->connector ??= new CavConnector($baseUrl, $apiKey);
    }

    /**
     * The longest a single call can take, used to decide whether the remaining budget can afford
     * another one. Guzzle's connect and request timeouts are not additive — `timeout` covers the
     * whole transfer — so the request timeout is the ceiling.
     */
    private function requestCeilingSeconds(): float
    {
        return (float) CavConnector::REQUEST_TIMEOUT_SECONDS;
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
