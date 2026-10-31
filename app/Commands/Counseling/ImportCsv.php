<?php

namespace App\Commands\Counseling;

/**
 * The counseling import's CSV (#565): the template's columns, and the parser that turns a file into {@see ImportRow}s.
 * The same for the Admin screen and the console command.
 *
 * The header names the columns, in any order, matched without regard to case or surrounding spaces; it must name at
 * least `drive_url` or `file`, and a column it leaves out is blank on every row. A column the template does not have
 * is ignored. A byte-order mark is dropped, blank rows are passed over, and each cell is cut to {@see CELL_MAX}
 * characters, so no cell can carry more than a name or a link.
 */
final class ImportCsv
{
    /** The template's columns, in its order. */
    public const COLUMNS = ['drive_url', 'file', 'type', 'trooper', 'kind', 'issuer', 'counseled_on'];

    /** The most rows one import takes: each Drive link is downloaded while the Admin waits for the check. */
    public const MAX_ROWS = 200;

    public const CELL_MAX = 500;

    /** The template the Admin downloads: the header line alone. */
    public static function template(): string
    {
        return implode(',', self::COLUMNS)."\n";
    }

    /**
     * @return list<ImportRow>
     *
     * @throws ImportRefusedException
     */
    public static function parse(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? '';
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $contents);
        rewind($handle);

        $header = fgetcsv($handle, escape: '');
        $columns = is_array($header) ? array_map(fn ($cell) => strtolower(trim((string) $cell)), $header) : [];

        if (array_intersect(['drive_url', 'file'], $columns) === []) {
            fclose($handle);

            throw ImportRefusedException::notCsv();
        }

        $rows = [];
        $line = 1;

        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $line++;

            if ($cells === [null] || implode('', array_map(fn ($cell) => trim((string) $cell), $cells)) === '') {
                continue;
            }

            if (count($rows) === self::MAX_ROWS) {
                fclose($handle);

                throw ImportRefusedException::tooManyRows();
            }

            $cell = function (string $column) use ($columns, $cells): string {
                $index = array_search($column, $columns, true);

                return $index === false ? '' : mb_substr(trim((string) ($cells[$index] ?? '')), 0, self::CELL_MAX);
            };

            $rows[] = new ImportRow(
                line: $line,
                driveUrl: $cell('drive_url'),
                file: $cell('file'),
                type: $cell('type'),
                trooper: $cell('trooper'),
                kind: $cell('kind'),
                issuer: $cell('issuer'),
                counseledOn: $cell('counseled_on'),
            );
        }

        fclose($handle);

        if ($rows === []) {
            throw ImportRefusedException::noRows();
        }

        return $rows;
    }
}
