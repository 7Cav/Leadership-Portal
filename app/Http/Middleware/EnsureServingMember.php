<?php

namespace App\Http\Middleware;

use App\Authorization\ActAs;
use App\Authorization\ServingSession;
use App\Commands\Auth\AdmitServingMember;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The sign-in gate ({@see AdmitServingMember}) decides admission once, at the
 * door; this keeps it true for the length of the session. A member discharged mid-session would
 * otherwise keep reading the open pages until the session expires, so each authenticated request
 * asks {@see ServingSession::mustEnd()} and ends the session when the user no longer qualifies.
 * A background `fetch` gets a 401 rather than a redirect it would only see as an opaque failure.
 */
class EnsureServingMember
{
    private const MESSAGE = "Your forum account isn't linked to a serving 7th Cavalry milpac.";

    public function __construct(private readonly ServingSession $serving) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The session is the signed-in account's: while an Admin acts as someone (#379) that is the Admin, not who the
        // request runs as (a person already checked to qualify, or a billet stand-in with no milpac of its own).
        $user = $request->attributes->get(ActAs::ADMIN_ATTRIBUTE) ?? $request->user();

        if ($user === null || ! $this->serving->mustEnd($user)) {
            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => self::MESSAGE], 401);
        }

        Inertia::clearHistory();

        return redirect()->route('login')->with('error', self::MESSAGE);
    }
}
