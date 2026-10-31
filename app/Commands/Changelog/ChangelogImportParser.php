<?php

namespace App\Commands\Changelog;

use App\Models\ChangelogEntry;
use DateTimeImmutable;

/**
 * Splits pasted Markdown into changelog entries for the bulk import:
 *
 *     ## 2026-10-29
 *     - One change, one line.
 *       Indented lines under a bullet are its additional information (Markdown).
 *     - Another change.
 *     - [major] A change that also shows under "Major changes".
 *
 * A change whose text starts with `[major]` (any case) is flagged major, and one that starts with `[technical]` (any
 * case) is flagged technical; the marker is dropped. Both markers on one change is an error. Every other change is
 * full-changelog only.
 *
 *     - [technical] Dependency updates.
 *
 * Each `## YYYY-MM-DD` heading starts a date, and each `- ` (or `* `) bullet under it is one entry. Text after the date
 * on the heading (after a hyphen, an en dash, an em dash or a colon) is taken as that date's first entry, so the older
 * `## 2026-10-29 - Title` shape still imports. Text before the first heading is ignored. A level-2 heading that is not
 * a date, an impossible date, a date with no entries, a line under a date that is neither a bullet nor indented, and
 * text that is too long are listed as errors with their line numbers; the import saves nothing while any error remains.
 */
class ChangelogImportParser
{
    private const HEADING = '/^##\s+(?<date>[^\s:\x{2013}\x{2014}]+)\s*(?:(?:-|\x{2013}|\x{2014}|:)\s*(?<text>.*?))?\s*$/u';

    private const MARKER = '/^\[(?<kind>major|technical)\]\s*(?<text>.*)$/iu';

    private const BULLET = '/^[-*]\s+(?<text>.*?)\s*$/u';

    /**
     * @return array{entries: list<array{line: int, date: string, body: string, details: ?string, major: bool, technical: bool}>, errors: list<array{line: int, message: string}>}
     */
    public function parse(string $markdown): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $entries = [];
        $errors = [];
        // The date being read: its heading line, the date, and whether any entry was found under it.
        $date = null;
        // The entry being read, its details lines still raw.
        $entry = null;
        $inFence = false;

        $closeEntry = function () use (&$entry, &$entries, &$errors): void {
            if ($entry === null) {
                return;
            }
            $details = $this->dedent($entry['details']);
            $kinds = [];
            while (preg_match(self::MARKER, $entry['body'], $marker) === 1) {
                $kinds[mb_strtolower($marker['kind'])] = true;
                $entry['body'] = $marker['text'];
            }
            $major = isset($kinds['major']);
            $technical = isset($kinds['technical']);
            if ($major && $technical) {
                $errors[] = ['line' => $entry['line'], 'message' => "Line {$entry['line']}: a change cannot be both [major] and [technical]."];
            } elseif ($entry['body'] === '') {
                $marked = $technical ? '[technical]' : '[major]';
                $errors[] = ['line' => $entry['line'], 'message' => "Line {$entry['line']}: the change has no text after {$marked}."];
            } elseif (mb_strlen($entry['body']) > ChangelogEntry::MAX_BODY_LENGTH) {
                $errors[] = ['line' => $entry['line'], 'message' => "Line {$entry['line']}: the change is longer than ".ChangelogEntry::MAX_BODY_LENGTH.' characters.'];
            } elseif ($details !== null && mb_strlen($details) > ChangelogEntry::MAX_DETAILS_LENGTH) {
                $errors[] = ['line' => $entry['line'], 'message' => "Line {$entry['line']}: the additional information is longer than ".ChangelogEntry::MAX_DETAILS_LENGTH.' characters.'];
            } else {
                $entries[] = ['line' => $entry['line'], 'date' => $entry['date'], 'body' => $entry['body'], 'details' => $details, 'major' => $major, 'technical' => $technical];
            }
            $entry = null;
        };

        $closeDate = function () use (&$date, &$errors, $closeEntry): void {
            $closeEntry();
            if ($date !== null && $date['valid'] && ! $date['found']) {
                $errors[] = ['line' => $date['line'], 'message' => "Line {$date['line']}: the date has no entries. Put each change on a line starting with \"- \"."];
            }
            $date = null;
        };

        foreach ($lines as $index => $line) {
            $number = $index + 1;
            $indented = preg_match('/^(\s{2,}|\t)/', $line) === 1;

            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $inFence = ! $inFence;
            }

            if (! $inFence && ! $indented && preg_match('/^##(?!#)/', $line)) {
                $closeDate();
                $date = ['line' => $number, 'date' => '', 'valid' => false, 'found' => false];

                if (! preg_match(self::HEADING, $line, $match)) {
                    $errors[] = ['line' => $number, 'message' => "Line {$number}: a heading must be a date like \"## 2026-10-29\"."];

                    continue;
                }

                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $match['date']);
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $match['date']) || $parsed === false || $parsed->format('Y-m-d') !== $match['date']) {
                    $errors[] = ['line' => $number, 'message' => "Line {$number}: \"{$match['date']}\" is not a date. Write it as YYYY-MM-DD."];

                    continue;
                }

                $date = ['line' => $number, 'date' => $match['date'], 'valid' => true, 'found' => false];

                $text = trim($match['text'] ?? '');
                if ($text !== '') {
                    $entry = ['line' => $number, 'date' => $match['date'], 'body' => $text, 'details' => []];
                    $date['found'] = true;
                }

                continue;
            }

            // Before the first heading, or under a heading that was not a date.
            if ($date === null || ! $date['valid']) {
                continue;
            }

            if (! $inFence && ! $indented && preg_match(self::BULLET, $line, $match)) {
                $closeEntry();
                if ($match['text'] === '') {
                    $errors[] = ['line' => $number, 'message' => "Line {$number}: the bullet has no text."];

                    continue;
                }
                $entry = ['line' => $number, 'date' => $date['date'], 'body' => $match['text'], 'details' => []];
                $date['found'] = true;

                continue;
            }

            if (trim($line) === '') {
                if ($entry !== null) {
                    $entry['details'][] = '';
                }

                continue;
            }

            if ($entry !== null && ($indented || $inFence)) {
                $entry['details'][] = $line;

                continue;
            }

            $errors[] = ['line' => $number, 'message' => "Line {$number}: start each change with \"- \", and indent its additional information under it."];
        }

        $closeDate();

        if ($entries === [] && $errors === []) {
            $errors[] = ['line' => 1, 'message' => 'No entries found. Start each date with a heading like "## 2026-10-29" and put each change on a "- " line under it.'];
        }

        return ['entries' => $entries, 'errors' => $errors];
    }

    /**
     * The details lines with their shared indent removed, blank lines at either end dropped, or null when none are left.
     *
     * @param  list<string>  $lines
     */
    private function dedent(array $lines): ?string
    {
        $indents = array_map(
            fn (string $line) => strlen($line) - strlen(ltrim($line, " \t")),
            array_filter($lines, fn (string $line) => trim($line) !== ''),
        );
        if ($indents === []) {
            return null;
        }
        $cut = min($indents);

        $text = trim(implode("\n", array_map(fn (string $line) => rtrim(substr($line, $cut)), $lines)), "\n");

        return $text === '' ? null : $text;
    }
}
