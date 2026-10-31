<?php

namespace App\Integration\GoogleSheets;

use App\Integration\GoogleSheets\Requests\AppendRowRequest;
use Throwable;

/**
 * The single door out to the reports Google Sheet (AD-3): it appends a weekly staff report's row to one tab of the
 * spreadsheet the env names, and does nothing else. It signs in as the portal's Google service account
 * ({@see GoogleServiceAccount}), so the sheet must be shared with that account's email as an editor.
 *
 * AD-23: the spreadsheet id and tab names come from env through config, injected by the composition root.
 */
class GoogleSheetsApi implements SheetsClient
{
    public const STAFF_TAB = 'Weekly Staff Report 2O26';

    public const BATTALION_TAB = 'Weekly Battalion Report 2O26';

    /**
     * @param  array{spreadsheet_id?: string|null, staff_tab?: string|null, battalion_tab?: string|null}|null  $config
     */
    public function __construct(
        private readonly GoogleServiceAccount $auth,
        private readonly ?array $config = [],
    ) {}

    public function isConfigured(): bool
    {
        return $this->spreadsheetId() !== '' && $this->auth->isConfigured();
    }

    public function staffTab(): string
    {
        return $this->tab('staff_tab', self::STAFF_TAB);
    }

    public function battalionTab(): string
    {
        return $this->tab('battalion_tab', self::BATTALION_TAB);
    }

    public function append(string $tab, array $row): void
    {
        if (! $this->isConfigured()) {
            throw GoogleSheetsUnavailable::misconfigured('GOOGLE_SHEETS_REPORTS_SPREADSHEET_ID, GOOGLE_SERVICE_ACCOUNT_EMAIL and GOOGLE_SERVICE_ACCOUNT_PRIVATE_KEY must all be set');
        }

        try {
            $response = (new GoogleSheetsConnector($this->auth->accessToken()))
                ->send(new AppendRowRequest($this->spreadsheetId(), $tab, array_values($row)));
        } catch (GoogleSheetsUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            throw GoogleSheetsUnavailable::unreachable($e);
        }

        if ($response->failed()) {
            throw GoogleSheetsUnavailable::httpError($response->status());
        }
    }

    private function spreadsheetId(): string
    {
        return trim((string) ($this->config['spreadsheet_id'] ?? ''));
    }

    private function tab(string $key, string $default): string
    {
        $value = trim((string) ($this->config[$key] ?? ''));

        return $value === '' ? $default : $value;
    }
}
