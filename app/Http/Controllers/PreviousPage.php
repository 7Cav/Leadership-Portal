<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * The page a request came from, read from its Referer, for the view-as and act-as switches that send the user back to
 * it. Only a page on this site: no other host, and a path that cannot be read as another host (`//...`), and no
 * backslash, space or control character a browser could drop or turn into one.
 */
final class PreviousPage
{
    /** The page's path and query as an address on this site, or null with no Referer or one off this site. */
    public static function of(Request $request): ?string
    {
        $previous = self::parts($request);

        return $previous === null ? null : $previous['path'].($previous['query'] === '' ? '' : '?'.$previous['query']);
    }

    /**
     * The path and query of that page, or null.
     *
     * @return array{path: string, query: string}|null
     */
    public static function parts(Request $request): ?array
    {
        $referer = $request->headers->get('referer');
        $parts = is_string($referer) ? parse_url($referer) : false;

        if ($parts === false || (isset($parts['host']) && strcasecmp($parts['host'], $request->getHost()) !== 0)) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')
            || preg_match('/[\x00-\x20\x7F]/', $path.($parts['query'] ?? '')) === 1) {
            return null;
        }

        return ['path' => $path, 'query' => $parts['query'] ?? ''];
    }
}
