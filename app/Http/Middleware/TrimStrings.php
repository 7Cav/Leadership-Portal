<?php

namespace App\Http\Middleware;

use App\Support\Text\FreeText;
use Closure;
use Illuminate\Foundation\Http\Middleware\TrimStrings as BaseTrimStrings;
use Illuminate\Http\Request;

/**
 * Laravel's trim, then FreeText's clean, on every request string. The except list (passwords) is honoured as before.
 */
class TrimStrings extends BaseTrimStrings
{
    /**
     * Paths that take in-game player names, which really do hold invisible and odd characters and must reach CRCON
     * matching exactly as typed (GameIdentityText::reveal shows them). Matched by path, not field key: 'text' is a
     * generic key, and this global middleware runs before routing, so there is no route name yet.
     */
    private const RAW_PATHS = [
        'game-identities',
        'game-identities/*',
        'rollcall/*/unmatched/*',
    ];

    /** Whether this request is on one of those paths: they keep Laravel's trim, as they always had, but skip the clean. */
    private bool $raw = false;

    public function handle($request, Closure $next)
    {
        $this->raw = $request instanceof Request && $request->is(...self::RAW_PATHS);

        return parent::handle($request, $next);
    }

    protected function transform($key, $value)
    {
        $except = array_merge($this->except, static::$neverTrim);

        if ($this->shouldSkip($key, $except) || ! is_string($value)) {
            return $value;
        }

        $trimmed = parent::transform($key, $value);

        return $this->raw ? $trimmed : FreeText::clean($trimmed);
    }
}
