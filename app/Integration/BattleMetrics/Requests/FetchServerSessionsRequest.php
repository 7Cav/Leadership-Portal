<?php

namespace App\Integration\BattleMetrics\Requests;

use DateTimeImmutable;
use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Reads the sessions on one server that overlap a window:
 * `GET {base}/servers/{id}/relationships/sessions`, with the players' identifiers included.
 *
 * The only request class in `Integration/BattleMetrics`; nothing that changes BattleMetrics
 * state (flags, notes, bans) is modelled anywhere. Later pages repeat the endpoint with the
 * query of the `links.next` URL BattleMetrics returned, passed as `$nextQuery` (Saloon refuses
 * an absolute URL as an endpoint, which also keeps the key on the configured host).
 */
class FetchServerSessionsRequest extends Request
{
    public const PAGE_SIZE = 100;

    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $serverId,
        private readonly DateTimeImmutable $start,
        private readonly DateTimeImmutable $end,
        /** @var array<string, mixed>|null */
        private readonly ?array $nextQuery = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/servers/'.rawurlencode($this->serverId).'/relationships/sessions';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        // The `links.next` query (the cursor) goes first and the window filters are written over it,
        // so a link carrying its own `start`, `stop` or `include` can never widen the read beyond the window.
        return [
            'page[size]' => self::PAGE_SIZE,
            ...($this->nextQuery ?? []),
            'start' => $this->start->format('Y-m-d\TH:i:s\Z'),
            'stop' => $this->end->format('Y-m-d\TH:i:s\Z'),
            'include' => 'identifier',
        ];
    }
}
