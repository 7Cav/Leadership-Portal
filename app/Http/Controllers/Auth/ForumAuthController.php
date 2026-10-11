<?php

namespace App\Http\Controllers\Auth;

use App\Commands\Auth\Admission;
use App\Commands\Auth\AdmitServingMember;
use App\Commands\Auth\BeginForumLogin;
use App\Commands\Auth\CavEnrichmentException;
use App\Commands\Auth\CompleteForumLogin;
use App\Commands\Auth\EnrichCavProfile;
use App\Commands\Auth\ForumLoginException;
use App\Http\Controllers\Controller;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Orchestration only (AD-1): no business logic, no HTTP-out. External calls run
 * through the Commands (→ Forum seam); this controller just moves the browser and
 * owns the session/auth lifecycle.
 */
class ForumAuthController extends Controller
{
    /** The session key holding the single-use OAuth CSRF `state` nonce. */
    private const STATE_KEY = 'forum_oauth_state';

    /** The session key holding this login's Cav roster snapshot (Story 1.6). */
    private const CAV_PROFILE_KEY = 'cav_profile';

    /**
     * The session key holding why enrichment produced nothing, as the string value of the
     * Integration layer's failure-reason enum (no type reference here on purpose — the
     * controller must not depend on `Integration/`). `auth.profile === null` alone conflates "roster down", "holds no milpac", "no key
     * configured" and "session predates 1.6" — Story 1.7 needs to tell those apart to say
     * anything useful to the user.
     */
    private const CAV_UNAVAILABLE_KEY = 'cav_profile_unavailable';

    /**
     * Provenance label shown wherever the enriched values render (AD-20 rule 1). Names the
     * system that owns the facts, so a stale rank is never mistaken for a portal assertion.
     */
    private const CAV_PROFILE_SOURCE = 'Cav roster (api.7cav.us)';

    /**
     * The login screen. Surfaces any error flashed by a failed callback (AC #5).
     */
    public function show(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            'error' => $request->session()->get('error'),
        ]);
    }

    /**
     * Begin the flow: mint state, stash it in the session, and send the browser to
     * the forum authorize endpoint (AC #1).
     */
    public function redirect(Request $request, BeginForumLogin $command): RedirectResponse
    {
        $result = $command->handle();

        $request->session()->put(self::STATE_KEY, $result->state);

        return redirect()->away($result->authorizeUrl);
    }

    /**
     * Handle the OAuth2 callback (AC #2, #5). Reject a denied/errored consent or a
     * missing/mismatched `state` BEFORE any token work — no partial session is ever
     * created; failures redirect back to login with a clear message (never a 500).
     *
     * A good handshake is not enough on its own: any 7cav.us forum account completes one. The
     * session starts only for an Admin or a user linked to a serving trooper
     * ({@see AdmitServingMember}); everyone else goes back to the login screen with the reason.
     */
    public function callback(Request $request, CompleteForumLogin $command, EnrichCavProfile $enrich, AdmitServingMember $admit): RedirectResponse
    {
        $expectedState = $request->session()->pull(self::STATE_KEY);
        $providedState = $request->query('state');

        $stateOk = is_string($expectedState)
            && is_string($providedState)
            && hash_equals($expectedState, $providedState);

        // A cancelled consent is benign; a state mismatch (CSRF / expired session) and a
        // code-less, error-less callback (OAuth protocol violation) are not — log those
        // so they're observable, while all three share one generic user message (AC #5).
        if ($request->filled('error')) {
            return $this->backToLogin('Sign-in was cancelled or could not be verified. Please try again.');
        }

        if (! $stateOk) {
            Log::warning('Forum OAuth callback rejected: state missing or mismatched (possible CSRF or expired session).');

            return $this->backToLogin('Sign-in was cancelled or could not be verified. Please try again.');
        }

        if (! $request->filled('code')) {
            Log::warning('Forum OAuth callback rejected: no authorization code and no error parameter.');

            return $this->backToLogin('Sign-in was cancelled or could not be verified. Please try again.');
        }

        try {
            $user = $command->handle((string) $request->query('code'));
        } catch (ForumLoginException $e) {
            // Generic message to the user; the cause chain (HTTP status / stage) is
            // reported so a wrong secret or a changed forum payload is debuggable.
            report($e);

            return $this->backToLogin('We could not complete sign-in with 7Cav. Please try again.');
        }

        // Enrich before deciding anything: it is what links (or re-confirms, or drops) the milpac id,
        // and what may confirm the trooper's roster live — both inputs to the admission check. The
        // portal identity row exists by now whatever the outcome; only the session is withheld.
        $cav = $this->enrichCavProfile($user, $enrich);

        $admission = $admit->handle($user, rosterUnavailable: $cav['roster_unavailable'], live: $cav['live']);

        if ($admission !== Admission::Admitted) {
            Log::info('Sign-in refused: the forum identity is not a confirmed serving 7th Cavalry member.', [
                'user_id' => $user->getKey(),
                'forum_user_id' => $user->forum_user_id,
                'admission' => $admission->name,
            ]);

            return $this->backToLogin($admission === Admission::Unverifiable
                ? "We couldn't reach the 7th Cavalry roster to confirm your membership. Try again in a few minutes."
                : "Your forum account isn't linked to a serving 7th Cavalry milpac.");
        }

        Auth::login($user);
        $request->session()->regenerate();

        $this->attachCavProfile($request, $cav);

        return redirect()->intended(route('home'));
    }

    /**
     * Resolve the trooper's Cav roster context (Story 1.6 AC #1), as the session-shaped snapshot
     * {@see attachCavProfile()} stores, or the reason there is none.
     *
     * Runs *before* `Auth::login()`: the admission check that follows reads the milpac link this
     * discovers and the roster Cav reports. It still cannot break the callback — every failure
     * becomes a reason, never an exception — and a failure on its own refuses nobody: a trooper
     * whose stored link the local roster cache confirms as serving signs in unenriched while Cav is
     * down (Story 1.7).
     *
     * The values are Cav's, not the portal's (AD-5), so they are stored with their source
     * and fetch time and never persisted as portal facts — whatever renders them can show
     * how fresh they are (AD-20, NFR7).
     *
     * Only genuine faults are reported. A forum member who holds no milpac is a normal,
     * expected population, and an ERROR with a stack trace on every one of their logins would
     * bury the case that matters — an actual outage — in noise operators learn to ignore.
     *
     * `unavailable` is the failure reason's string value (Story 1.7's input); `roster_unavailable`
     * is whether that failure says nothing about the user — anything but "holds no milpac"; `live`
     * is the profile Cav returned, for the admission check (deliberately untyped here: the
     * controller must not name an `Integration/` type).
     *
     * @return array{profile: ?array<string, mixed>, unavailable: ?string, roster_unavailable: bool, live: mixed}
     */
    private function enrichCavProfile(User $user, EnrichCavProfile $enrich): array
    {
        try {
            $profile = $enrich->handle($user);
        } catch (CavEnrichmentException $e) {
            if ($e->reason->isFault()) {
                report($e);
            } else {
                Log::info('Cav roster context unavailable at sign-in: the trooper holds no milpac.', [
                    'user_id' => $user->getKey(),
                    'forum_user_id' => $user->forum_user_id,
                ]);
            }

            return ['profile' => null, 'unavailable' => $e->reason->value, 'roster_unavailable' => $e->reason->isFault(), 'live' => null];
        } catch (Throwable $e) {
            // The one place in this codebase where a bare `Throwable` catch is the correct call.
            // Everything below promises that enrichment cannot break a login, but that promise
            // currently rests on four layers each being individually exhaustive — one new logging
            // handler, container binding or DB driver quirk on this path re-breaks it silently.
            // Roster context is optional and the admission check reads the local cache, so there is
            // no failure here worth trading a working login for. It proves nothing about the user,
            // so it counts as the roster being unavailable.
            report($e);

            return ['profile' => null, 'unavailable' => 'unexpected', 'roster_unavailable' => true, 'live' => null];
        }

        return [
            'profile' => [
                'rank_short' => $profile->rankShort,
                'rank_full' => $profile->rankFull,
                'billet' => $profile->billet,
                'mos' => $profile->mos,
                // The label, not the wire token: `ROSTER_TYPE_COMBAT` should never reach a view.
                'roster' => $profile->roster?->label(),
                'source' => self::CAV_PROFILE_SOURCE,
                'fetched_at' => $profile->fetchedAt->format(DateTimeInterface::ATOM),
            ],
            'unavailable' => null,
            'roster_unavailable' => false,
            // Handed to the admission check as is: Cav's live answer on which roster the trooper
            // stands, which outranks the cache when there is one. Never stored in the session.
            'live' => $profile,
        ];
    }

    /**
     * Attach the resolved roster context to the now-established session. Story 1.7 owns what the
     * *user* is told about a missing one; the reason is stashed alongside so that story is a view
     * change rather than a re-plumbing of this callback.
     *
     * @param  array{profile: ?array<string, mixed>, unavailable: ?string, roster_unavailable: bool, live: mixed}  $cav
     */
    private function attachCavProfile(Request $request, array $cav): void
    {
        // Clear first, so no path can leave a previous snapshot standing. `session()->regenerate()`
        // migrates the session id but carries its attributes across, so on a shared browser the
        // prior trooper's rank would otherwise survive into this login and render — stamped with a
        // source and fetch time that make it look authoritative.
        $request->session()->forget([self::CAV_PROFILE_KEY, self::CAV_UNAVAILABLE_KEY]);

        if ($cav['profile'] !== null) {
            $request->session()->put(self::CAV_PROFILE_KEY, $cav['profile']);
        } else {
            $request->session()->put(self::CAV_UNAVAILABLE_KEY, $cav['unavailable']);
        }
    }

    /**
     * End the session (Story 1.5 AC #1). Dropping the auth state is not enough on its own:
     * `invalidate()` flushes the payload and migrates the session id so the old one cannot
     * be replayed, and `regenerateToken()` rotates the CSRF token so a token captured
     * while signed in is worthless afterwards (NFR5, proposal §10.2). Order matters —
     * `Auth::logout()` first, so nothing writes the user back into the fresh session.
     *
     * `clearHistory()` is what actually satisfies AC #2 on this stack. Killing the session
     * and sending `no-store` govern what the *server* answers and what the *HTTP cache*
     * keeps — but pressing "back" in an Inertia SPA fires `popstate`, and the client
     * re-renders the stored page object without issuing a request at all. Clearing the
     * history tells it to discard that stored state, so "back" has to hit the server and
     * gets bounced to login. It runs last because the flag lives in the new session.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Inertia::clearHistory();

        return redirect()->route('login');
    }

    private function backToLogin(string $message): RedirectResponse
    {
        return redirect()->route('login')->with('error', $message);
    }
}
