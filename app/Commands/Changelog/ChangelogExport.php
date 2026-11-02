<?php

namespace App\Commands\Changelog;

use App\Models\ChangelogEntry;
use App\Repositories\ChangelogRepository;

/**
 * Every entry as Markdown in the shape {@see ChangelogImportParser} reads, technical ones included, each change ending
 * with its id (`{#42}`) so an import of the edited file updates that entry instead of adding it again:
 *
 *     ## 2026-10-29
 *     - [major] Added the roster. {#42}
 *       Additional information, indented.
 *     - Fixed the sidebar. {#41}
 *
 * Dates newest first; within a date the latest written first, the order the import reads them in, so importing an
 * export unchanged changes nothing. Writes nothing.
 */
class ChangelogExport
{
    public function __construct(private readonly ChangelogRepository $changelog) {}

    public function markdown(): string
    {
        $lines = ['# Changelog', ''];
        $date = null;

        foreach ($this->changelog->all() as $entry) {
            $day = $entry->date->toDateString();
            if ($day !== $date) {
                if ($date !== null) {
                    $lines[] = '';
                }
                $lines[] = "## {$day}";
                $date = $day;
            }
            $lines[] = '- '.self::marker($entry).$entry->body." {#{$entry->id}}";
            if ($entry->details !== null) {
                foreach (preg_split('/\r\n|\r|\n/', $entry->details) ?: [] as $line) {
                    $lines[] = trim($line) === '' ? '' : '  '.$line;
                }
            }
        }

        return implode("\n", $lines)."\n";
    }

    private static function marker(ChangelogEntry $entry): string
    {
        return match (true) {
            (bool) $entry->major => '[major] ',
            (bool) $entry->technical => '[technical] ',
            default => '',
        };
    }
}
