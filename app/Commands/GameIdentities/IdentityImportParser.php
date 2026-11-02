<?php

namespace App\Commands\GameIdentities;

use App\Models\GameIdentityText;

/**
 * Reads the text of a game identity import (a pasted list or a .txt or .csv file the page read): one identity per line,
 * `trooper, game, ID kind, ID`, separated by commas or tabs. The ID kind may be left out (three columns) or left empty.
 * Blank lines and lines starting with `#` are ignored, and so is a header line whose first column is "trooper". A
 * comma-separated line may quote a column ("Hell Let Loose, Vietnam" would need quotes). Every column is cleaned of
 * hidden and control characters ({@see GameIdentityText::clean()}). Only reads; it knows nothing of troopers or games.
 */
final class IdentityImportParser
{
    /** The most text one import may hold, in bytes (256 KB). */
    public const MAX_BYTES = 262144;

    /** The most identity lines one import may hold. */
    public const MAX_LINES = 500;

    /**
     * @return list<array{line: int, cells: list<string>}> each identity line with its line number in the text
     *
     * @throws IdentityImportException when the text is not text, is too large, holds too many lines or none at all.
     */
    public function parse(string $text): array
    {
        if (strlen($text) > self::MAX_BYTES) {
            throw IdentityImportException::tooLarge();
        }

        if (str_contains($text, "\0") || ! mb_check_encoding($text, 'UTF-8')) {
            throw IdentityImportException::notText();
        }

        // A byte order mark at the start of a file saved by a spreadsheet.
        $text = preg_replace('/^\x{FEFF}/u', '', $text) ?? $text;
        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $index => $raw) {
            $line = trim($raw);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $cells = str_contains($line, "\t") ? explode("\t", $line) : str_getcsv($line, ',', '"', '');
            $cells = array_map(fn (?string $cell) => GameIdentityText::clean((string) $cell), $cells);

            if ($rows === [] && in_array(mb_strtolower($cells[0]), ['trooper', 'cav name', 'name'], true)) {
                continue;
            }

            // Trailing empty columns (a spreadsheet's "a,b,c,") do not count.
            while (count($cells) > 3 && end($cells) === '') {
                array_pop($cells);
            }

            if (array_filter($cells, fn (string $cell) => $cell !== '') === []) {
                continue;
            }

            $rows[] = ['line' => $index + 1, 'cells' => $cells];

            if (count($rows) > self::MAX_LINES) {
                throw IdentityImportException::tooManyLines();
            }
        }

        if ($rows === []) {
            throw IdentityImportException::empty();
        }

        return $rows;
    }
}
