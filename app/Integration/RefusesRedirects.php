<?php

namespace App\Integration;

use Saloon\Http\Response;

/**
 * Every connector sends its key on each request. Guzzle follows redirects by default and strips only
 * `Authorization` and `Cookie` on a cross-origin hop, so a custom key header (`XF-Api-Key`,
 * `X-Api-Key`) would be replayed to whatever host a redirect names, and a path guard that checked the
 * first URL would be bypassed. No upstream we call is meant to redirect, so a 3xx is not followed and
 * counts as a failed call, which each seam already turns into its own typed failure.
 */
trait RefusesRedirects
{
    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return ['allow_redirects' => false];
    }

    public function hasRequestFailed(Response $response): ?bool
    {
        return $response->redirect() ? true : null;
    }
}
