<?php

namespace App\Http\Middleware;

use App\Authorization\ActAs;
use App\Authorization\Acting;
use App\Authorization\ScopeResolver;
use App\Repositories\PageVisitRepository;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records each page a signed-in person opens (#382), for the Admin-only activity log that lets a bug report be
 * retraced. A page is a GET answered with a rendered page: a full load (HTML) or an Inertia navigation. JSON fetches
 * (the trooper search, the view-as options, an autosave), redirects, errors, Inertia partial reloads and prefetches
 * are not visits, and nothing but a GET ever is.
 *
 * Only the address and the time are kept, never a form or a body: the query is dropped under `/auth` and `/__e2e`
 * (sign-in round trips), and a sensitive-looking parameter (`code`, `state`, a token, a password) is dropped anywhere.
 *
 * The person is the real account: while an Admin acts as someone (#379) that is the Admin, with who or what they acted
 * as beside it; a view as (AD-9) in effect is recorded the same way.
 *
 * The write happens in {@see terminate()}, after the response has gone out, so the log never slows a page, and a
 * failure is reported, never thrown at the visitor. A visit is not a state change in the AD-16 sense, so it writes no
 * `audit_log` row: it would flood the audit trail with reads.
 */
class RecordPageVisit
{
    /** Set during the request, read after it: what {@see terminate()} records. */
    private const ATTRIBUTE = 'page_visit';

    /** Paths whose query string is never kept: the forum sign-in round trip and the browser-test sign-in. */
    private const QUERYLESS_PREFIXES = ['auth', '__e2e'];

    /** A query parameter whose name matches is dropped on every route. */
    private const SENSITIVE_PARAMETER = '/^(code|state|key|sig|jwt|otp|auth|pin|hmac|nonce)$|token|password|passwd|secret|signature|api[_-]?key|session|credential|bearer|authori[sz]ation/i';

    public function __construct(
        private readonly PageVisitRepository $visits,
        private readonly ScopeResolver $scope,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Taken before the request runs: nothing it does to the clock or the session moves the visit.
        $request->attributes->set(self::ATTRIBUTE, CarbonImmutable::now('UTC'));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $at = $request->attributes->get(self::ATTRIBUTE);

            if (! $at instanceof CarbonImmutable || ! $this->isPageVisit($request, $response)) {
                return;
            }

            $user = $request->user();
            $admin = $request->attributes->get(ActAs::ADMIN_ATTRIBUTE);
            $acting = $request->attributes->get(ActAs::ACTING_ATTRIBUTE);
            $real = $admin ?? $user;

            if ($real === null) {
                return;
            }

            $this->visits->record(
                userId: (int) $real->getAuthIdentifier(),
                url: self::address($request),
                actingAs: $admin !== null && $acting instanceof Acting ? $acting->label : null,
                // What the shell showed on this request: the view as of whoever the request ran as.
                viewingAs: $user === null ? null : $this->scope->activeViewAs($user)?->label,
                at: $at,
            );
        } catch (Throwable $e) {
            // The page is already served; a lost log line must never become the visitor's problem.
            report($e);
        }
    }

    /** The path and the query, minus anything that must not be kept. The repository cuts it to the column. */
    public static function address(Request $request): string
    {
        $path = '/'.ltrim($request->path(), '/');

        if ($request->is(...array_merge(self::QUERYLESS_PREFIXES, array_map(fn ($prefix) => "{$prefix}/*", self::QUERYLESS_PREFIXES)))) {
            return $path;
        }

        $query = Arr::query(self::withoutSensitive($request->query()));

        return $query === '' ? $path : "{$path}?{$query}";
    }

    /**
     * The parameters minus every sensitive-looking one, at any depth (`filter[token]=...` too).
     *
     * @param  array<array-key, mixed>  $parameters
     * @return array<array-key, mixed>
     */
    private static function withoutSensitive(array $parameters): array
    {
        $kept = [];

        foreach ($parameters as $key => $value) {
            if (preg_match(self::SENSITIVE_PARAMETER, (string) $key) !== 1) {
                $kept[$key] = is_array($value) ? self::withoutSensitive($value) : $value;
            }
        }

        return $kept;
    }

    private function isPageVisit(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || $response->getStatusCode() !== 200) {
            return false;
        }

        // A partial reload refreshes props on the page already open; a prefetch is a page not yet opened.
        if ($request->hasHeader('X-Inertia-Partial-Component') || $request->header('Purpose') === 'prefetch') {
            return false;
        }

        if ($response->headers->get('X-Inertia') === 'true') {
            return true;
        }

        return ! $request->hasHeader('X-Inertia')
            && str_starts_with((string) $response->headers->get('Content-Type'), 'text/html');
    }
}
