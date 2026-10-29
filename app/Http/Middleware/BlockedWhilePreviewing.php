<?php

namespace App\Http\Middleware;

use App\Authorization\ActAs;
use App\Authorization\ScopeResolver;
use App\Authorization\ViewAsKind;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Starting or stopping view as or act as keeps the page the user was on, which the previewed or acted-as identity may
 * not be allowed to open. While either is on, a page refused with a 403 or 404 opens as the shell with a plain blocked
 * state instead of the bare error page, so the banner with its way out stays on screen. The refusal itself is
 * unchanged: the page's own response is thrown away whole, the status stays, and the blocked page carries no data of
 * the page, only who is previewed or acted as. Page loads only: a write, or a route that does not exist, keeps its
 * own answer.
 */
class BlockedWhilePreviewing
{
    public function __construct(private readonly ScopeResolver $scope) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! in_array($response->getStatusCode(), [403, 404], true)
            || ! $request->isMethod('GET')
            || $request->route() === null
            || $request->expectsJson() && ! $request->header('X-Inertia')
            || ($user = $request->user()) === null) {
            return $response;
        }

        $acting = $request->attributes->get(ActAs::ACTING_ATTRIBUTE);
        $target = match (true) {
            $acting !== null => ['mode' => 'actAs', 'kind' => $acting->billet === null ? 'trooper' : 'billet'],
            ($preview = $this->scope->activeViewAs($user)) !== null => [
                'mode' => 'viewAs',
                'kind' => $preview->target->kind === ViewAsKind::Unit ? 'unit' : 'trooper',
            ],
            default => null,
        };

        if ($target === null) {
            return $response;
        }

        return Inertia::render('Blocked', $target)->toResponse($request)->setStatusCode($response->getStatusCode());
    }
}
