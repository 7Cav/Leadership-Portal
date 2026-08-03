<?php

namespace App\Integration\Cav\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Fetches one roster as a map of milpac relation id → lite profile.
 * `GET {base}/api/v1/roster/{roster}/lite`.
 *
 * The `/lite` variant is used deliberately: it drops the per-trooper records and awards
 * arrays while keeping rank, primary position and MOS, which is everything enrichment
 * needs. Measured, the three serving rosters together are ~77 KB gzipped.
 *
 * This is also the *only* endpoint that reveals a trooper's milpac relation id — it is
 * the map key, and appears nowhere in a profile body.
 */
class FetchRosterRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $roster) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/roster/{$this->roster}/lite";
    }
}
