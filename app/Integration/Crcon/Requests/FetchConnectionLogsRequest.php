<?php

namespace App\Integration\Crcon\Requests;

use DateTimeImmutable;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * One server's CONNECTED and DISCONNECTED log lines in a span, oldest first. Read-only.
 *
 * `action` with `exact_action` off is a case-insensitive substring match, so `CONNECTED` matches both actions and
 * nothing else. `server_filter` keeps lines of the one server number, which matters when instances share a database.
 */
class FetchConnectionLogsRequest extends Request implements HasBody
{
    use HasJsonBody;

    public const PAGE_SIZE = 1000;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $serverNumber,
        private readonly DateTimeImmutable $from,
        private readonly DateTimeImmutable $till,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/get_historical_logs';
    }

    protected function defaultBody(): array
    {
        return [
            'action' => 'CONNECTED',
            'exact_action' => false,
            'server_filter' => $this->serverNumber,
            'from' => $this->from->format('Y-m-d\TH:i:s'),
            'till' => $this->till->format('Y-m-d\TH:i:s'),
            'time_sort' => 'asc',
            'limit' => self::PAGE_SIZE,
        ];
    }
}
