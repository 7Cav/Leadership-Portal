<?php

namespace App\Integration\Cav\Requests;

use Carbon\CarbonImmutable;
use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Fetches the leave of absence the roster API reads live from the LOA forums:
 * `GET {base}/api/v1/loas[?endedSince=YYYY-MM-DD]`. Needs the `read:loas` scope.
 *
 * Without `endedSince` the answer is every open and upcoming LOA (and the needs-review ones posted in the last
 * 14 days). With it, the LOAs that ended on or after that day, and the needs-review ones posted on or after it,
 * are included too. The API refuses a date more than 365 days back.
 */
class FetchLoasRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly ?CarbonImmutable $endedSince = null) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/loas';
    }

    /** @return array<string, string> */
    protected function defaultQuery(): array
    {
        return $this->endedSince === null ? [] : ['endedSince' => $this->endedSince->utc()->toDateString()];
    }
}
