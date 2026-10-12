<?php

namespace App\Integration\Crcon\Requests;

use DateTimeImmutable;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Reads CRCON's player history for players last seen inside a window:
 * `POST {base}/api/get_players_history`.
 *
 * Read-only despite the verb — CRCON's history endpoint takes its filters as a JSON body, and
 * this is a query, not a command. It is the only request class in `Integration/Crcon`; no
 * server-administration endpoint (kick, ban, message, map change) is modelled anywhere.
 */
class FetchPlayersHistoryRequest extends Request implements HasBody
{
    use HasJsonBody;

    public const PAGE_SIZE = 500;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly DateTimeImmutable $from,
        private readonly int $page = 1,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/get_players_history';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            // Anyone last seen before the window opened cannot have been present in it. The
            // upper bound is applied by the seam against each player's sessions instead, since
            // someone seen after the window may also have been there during it.
            'last_seen_from' => $this->from->format('Y-m-d\TH:i:s'),
            'page' => $this->page,
            'page_size' => self::PAGE_SIZE,
        ];
    }
}
