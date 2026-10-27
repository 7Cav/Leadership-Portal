<?php

namespace App\Http\Middleware;

use App\Authorization\ScopeResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * View-as is a read-only preview (Story 1.13): while a viewer is seeing the portal as someone
 * else, no state-changing request goes through, whatever the page shows (AD-6). Leaving the
 * preview and signing out stay open, so the viewer is never locked in.
 */
class ReadOnlyWhileViewingAs
{
    public function __construct(private readonly ScopeResolver $scope) {}

    public function handle(Request $request, Closure $next): Response
    {
        $viewer = $request->user();

        if ($viewer === null || $request->isMethodSafe() || $request->routeIs('view-as.store', 'view-as.destroy', 'act-as.store', 'act-as.destroy', 'logout')) {
            return $next($request);
        }

        if ($this->scope->activeViewAs($viewer) !== null) {
            abort(403, 'View as is a read-only preview. Exit view as to make changes.');
        }

        return $next($request);
    }
}
