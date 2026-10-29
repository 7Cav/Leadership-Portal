<?php

namespace App\Commands\Changelog;

use App\Models\ChangelogEntry;
use DateTimeImmutable;

/**
 * Splits pasted Markdown into changelog entries for the bulk import. Each `## YYYY-MM-DD - Title` heading starts an
 * entry, and the text up to the next level-2 heading is its body. After the date a hyphen, an en dash, an em dash or a
 * colon separates the title. Text before the first heading is ignored. A level-2 heading that does not have that
 * shape, an impossible date, a missing title or body, or a title or body that is too long is listed as an error with
 * its line number; the import saves nothing while any error remains.
 */
class ChangelogImportParser
{
    private const HEADING = '/^##\s+(?<date>[^\s:\x{2013}\x{2014}]+)\s*(?:-|\x{2013}|\x{2014}|:)\s*(?<title>.*?)\s*$/u';

    /**
     * @return array{entries: list<array{line: int, date: string, title: string, body: string}>, errors: list<array{line: int, message: string}>}
     */
    public function parse(string $markdown): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $entries = [];
        $errors = [];
        $current = null;
        $inFence = false;

        $close = function () use (&$current, &$entries, &$errors): void {
            if ($current === null) {
                return;
            }
            $body = trim(implode("\n", $current['body']));
            if ($current['valid']) {
                if ($body === '') {
                    $errors[] = ['line' => $current['line'], 'message' => "Line {$current['line']}: the entry has no text under its heading."];
                } elseif (mb_strlen($body) > ChangelogEntry::MAX_BODY_LENGTH) {
                    $errors[] = ['line' => $current['line'], 'message' => "Line {$current['line']}: the entry text is longer than ".ChangelogEntry::MAX_BODY_LENGTH.' characters.'];
                } else {
                    $entries[] = ['line' => $current['line'], 'date' => $current['date'], 'title' => $current['title'], 'body' => $body];
                }
            }
            $current = null;
        };

        foreach ($lines as $index => $line) {
            $number = $index + 1;

            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $inFence = ! $inFence;
            }

            if (! $inFence && preg_match('/^##(?!#)/', $line)) {
                $close();
                $current = ['line' => $number, 'date' => '', 'title' => '', 'body' => [], 'valid' => false];

                if (! preg_match(self::HEADING, $line, $match)) {
                    $errors[] = ['line' => $number, 'message' => "Line {$number}: a heading must look like \"## 2026-10-29 - Title\"."];

                    continue;
                }

                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $match['date']);
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $match['date']) || $date === false || $date->format('Y-m-d') !== $match['date']) {
                    $errors[] = ['line' => $number, 'message' => "Line {$number}: \"{$match['date']}\" is not a date. Write it as YYYY-MM-DD."];

                    continue;
                }

                $title = trim($match['title']);
                if ($title === '') {
                    $errors[] = ['line' => $number, 'message' => "Line {$number}: the heading has no title after the date."];

                    continue;
                }
                if (mb_strlen($title) > ChangelogEntry::MAX_TITLE_LENGTH) {
                    $errors[] = ['line' => $number, 'message' => "Line {$number}: the title is longer than ".ChangelogEntry::MAX_TITLE_LENGTH.' characters.'];

                    continue;
                }

                $current = ['line' => $number, 'date' => $match['date'], 'title' => $title, 'body' => [], 'valid' => true];

                continue;
            }

            if ($current !== null) {
                $current['body'][] = $line;
            }
        }

        $close();

        if ($entries === [] && $errors === []) {
            $errors[] = ['line' => 1, 'message' => 'No entries found. Start each entry with a heading like "## 2026-10-29 - Title".'];
        }

        return ['entries' => $entries, 'errors' => $errors];
    }
}
