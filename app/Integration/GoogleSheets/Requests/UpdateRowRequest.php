<?php

namespace App\Integration\GoogleSheets\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Overwrites one row in place: `PUT /v4/spreadsheets/{id}/values/{range}`, the range being the one an append returned
 * (`'Tab'!A57:V57`). `RAW` as on the append, so a cell is kept as written.
 */
class UpdateRowRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::PUT;

    /**
     * @param  list<string>  $row
     */
    public function __construct(
        private readonly string $spreadsheetId,
        private readonly string $range,
        private readonly array $row,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/v4/spreadsheets/'.rawurlencode($this->spreadsheetId).'/values/'.rawurlencode($this->range);
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return ['valueInputOption' => 'RAW'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return ['range' => $this->range, 'majorDimension' => 'ROWS', 'values' => [$this->row]];
    }
}
