<?php

namespace App\Http\Middleware;

use App\Authorization\ActAs;
use App\Authorization\ActingBillet;
use App\Support\Audit\ActingFor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the request as whoever an Admin, or a leader acting as someone below them (#432), is acting as: a person, or a
 * stand-in holding a billet ({@see ActAs}). The session stays the real user's: only this request's user is swapped, so every gate (the ScopeResolver, each
 * Command's checks) sees exactly those permissions, Admin screens included as refused. The real Admin is kept on the
 * request ({@see ActAs::ADMIN_ATTRIBUTE}) for the act-as stop route, the serving check and sign-out, and handed to
 * {@see ActingFor} so each audit entry names the Admin and who or what they acted as.
 */
class ActAsUser
{
    public function __construct(
        private readonly ActAs $actAs,
        private readonly ActingFor $acting,
        private readonly ActingBillet $billet,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Never carry an earlier request's acting into this one, in a worker that serves several.
        $this->acting->clear();
        $this->billet->clear();

        $admin = $request->user();
        $acting = $admin === null ? null : $this->actAs->target($admin);

        if ($acting === null) {
            return $next($request);
        }

        $user = $acting->as;
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set(ActAs::ADMIN_ATTRIBUTE, $admin);
        $request->attributes->set(ActAs::ACTING_ATTRIBUTE, $acting);

        if ($acting->billet !== null) {
            // A billet: the Admin's stand-in commands that unit and nothing else, and their actions are their own,
            // recorded in that unit's leader's place.
            $this->billet->hold($user, $acting->billet);
            $this->acting->setBillet($admin->id, $acting->billet->id);
        } else {
            $this->acting->set($admin->id, $user->id);
        }

        return $next($request);
    }
}
