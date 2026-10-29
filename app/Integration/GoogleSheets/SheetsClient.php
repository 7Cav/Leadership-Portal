<?php

namespace App\Integration\GoogleSheets;

/**
 * The reports Google Sheet the weekly staff reports are copied to (AD-3): the one place a staff report row leaves the
 * portal. {@see GoogleSheetsApi} talks to Google; tests bind a fake, so no test ever calls Google.
 */
interface SheetsClient
{
    /** Whether a spreadsheet and the service account are set. When not, nothing is pushed and nothing fails. */
    public function isConfigured(): bool;

    /** The tab the platoon and company HQ staff reports go to. */
    public function staffTab(): string;

    /** The tab the battalion HQ staff reports go to (ACD included). */
    public function battalionTab(): string;

    /**
     * Append one row, its cells in the tab's column order, below the last row of `$tab`.
     *
     * @param  list<string>  $row
     *
     * @throws GoogleSheetsUnavailable when the sheet does not take it
     */
    public function append(string $tab, array $row): void;
}
