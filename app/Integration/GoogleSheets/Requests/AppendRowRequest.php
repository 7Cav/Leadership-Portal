<?php

namespace App\Integration\GoogleSheets\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Appends one row below a tab's table: `POST /v4/spreadsheets/{id}/values/{range}:append`, the range being the tab's
 * first cell. `RAW` so a cell is kept as written (a name starting with `=` stays text), `INSERT_ROWS` so the row never
 * lands on top of another.
 */
class AppendRowRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  list<string>  $row
     */
    public function __construct(
        private readonly string $spreadsheetId,
        private readonly string $tab,
        private readonly array $row,
    ) {}

    public function resolveEndpoint(): string
    {
        $range = "'".str_replace("'", "''", $this->tab)."'!A1";

        return '/v4/spreadsheets/'.rawurlencode($this->spreadsheetId).'/values/'.rawurlencode($range).':append';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return ['valueInputOption' => 'RAW', 'insertDataOption' => 'INSERT_ROWS'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return ['majorDimension' => 'ROWS', 'values' => [$this->row]];
    }
}
