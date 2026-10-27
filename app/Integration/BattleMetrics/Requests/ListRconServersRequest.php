<?php

namespace App\Integration\BattleMetrics\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Lists the servers the API key has RCON access to, which are the regiment's own (#389):
 * `GET {base}/servers?filter[rcon]=true`. A read; later pages repeat it with the `links.next` query, as
 * {@see FetchServerSessionsRequest} does.
 */
class ListRconServersRequest extends Request
{
    public const PAGE_SIZE = 100;

    protected Method $method = Method::GET;

    public function __construct(
        /** @var array<string, mixed>|null */
        private readonly ?array $nextQuery = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/servers';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        // The filter is written over the cursor, so a link can never widen the listing past the key's own servers.
        return [
            'page[size]' => self::PAGE_SIZE,
            ...($this->nextQuery ?? []),
            'filter[rcon]' => 'true',
        ];
    }
}
