<?php

namespace App\Http\Controllers\Auth;

use App\Commands\Auth\BeginForumLogin;
use App\Commands\Auth\CompleteForumLogin;
use App\Commands\Auth\ForumLoginException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Orchestration only (AD-1): no business logic, no HTTP-out. External calls run
 * through the Commands (→ Forum seam); this controller just moves the browser and
 * owns the session/auth lifecycle.
 */
class ForumAuthController extends Controller
{
    /** The session key holding the single-use OAuth CSRF `state` nonce. */
    private const STATE_KEY = 'forum_oauth_state';

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
     */
    public function callback(Request $request, CompleteForumLogin $command): RedirectResponse
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

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
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
