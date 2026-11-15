<?php

namespace App\Support\Audit;

use Illuminate\Http\Request;

/**
 * The page a change was made on, for its audit entry: the portal page the browser was showing when it sent the write,
 * as the request's `Referer` says. Only a page on this portal is kept, as its path and query (`/rollcall/12?week=...`),
 * so an entry never stores another site's address. Null for a write with no request behind it (a scheduled sync, a
 * queued job) or none of the portal's pages.
 */
class AuditPage
{
    /** As much of the address as the column holds. */
    public const MAX = 500;

    public static function current(): ?string
    {
        $request = app()->bound('request') ? app('request') : null;

        return $request instanceof Request ? self::of($request) : null;
    }

    public static function of(Request $request): ?string
    {
        $referer = $request->headers->get('referer');

        if (! is_string($referer) || $referer === '') {
            return null;
        }

        $host = parse_url($referer, PHP_URL_HOST);
        $path = parse_url($referer, PHP_URL_PATH);
        $query = parse_url($referer, PHP_URL_QUERY);

        if (! is_string($host) || mb_strtolower($host) !== mb_strtolower($request->getHost()) || ! is_string($path)) {
            return null;
        }

        $page = mb_substr($path.(is_string($query) && $query !== '' ? "?{$query}" : ''), 0, self::MAX);

        return self::isLocal($page) ? $page : null;
    }

    /**
     * Whether `$page` is a path on this portal, as the docs' "?" help reads one: it starts with a single `/`, so
     * `//evil.example` (another site to a browser) is refused, and holds no backslash or control character, which a
     * browser may read as a `/` or drop (`/\evil.example` and `/\t/evil.example` both open evil.example).
     */
    public static function isLocal(string $page): bool
    {
        return str_starts_with($page, '/') && ! str_starts_with($page, '//') && preg_match('/[\x00-\x1F\x7F\\\\]/', $page) !== 1;
    }
}
