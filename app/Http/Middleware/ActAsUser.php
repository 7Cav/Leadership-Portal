<?php

namespace App\Http\Middleware;

use App\Authorization\ActAs;
use App\Support\Audit\ActingFor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the request as the person an Admin is acting as (#379). The session stays the Admin's: only this request's
 * user is swapped, so every gate (the ScopeResolver, each Command's checks) sees exactly that person's permissions,
 * Admin screens included as refused. The real Admin is kept on the request ({@see ActAs::ADMIN_ATTRIBUTE}) for the act-as stop route
 * and sign-out, and handed to {@see ActingFor} so each audit entry names the Admin acting on the person's behalf.
 */
class ActAsUser
{
    public function __construct(
        private readonly ActAs $actAs,
        private readonly ActingFor $acting,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();
        $user = $admin === null ? null : $this->actAs->target($admin);

        if ($admin === null || $user === null) {
            return $next($request);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set(ActAs::ADMIN_ATTRIBUTE, $admin);
        $this->acting->set($admin->id, $user->id);

        return $next($request);
    }
}
