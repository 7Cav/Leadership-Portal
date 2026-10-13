<?php

namespace App\Http\Middleware;

use App\Authorization\ScopeResolver;
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
 * checks the roster cache's standing for the user's linked trooper and ends the session when it is
 * a departed one. Admins are never locked out by roster data (AD-7). A user the cache holds no
 * trooper for is let through: sign-in may have admitted them on Cav's live answer ahead of the
 * next sync, and the absence of a record is no evidence against them.
 */
class EnsureServingMember
{
    public function __construct(private readonly ScopeResolver $scope) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $this->scope->hasDeparted($user)) {
            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Inertia::clearHistory();

        return redirect()->route('login')
            ->with('error', "Your forum account isn't linked to a serving 7th Cavalry milpac.");
    }
}
