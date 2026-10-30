<?php

namespace App\Commands\Counseling;

/**
 * Splits the long free-text answers on the counseling PDF into several table rows, because dompdf cannot break one
 * table row across pages: a cell taller than the page runs off its bottom and the text is lost. With many short rows
 * the page breaks between them and the table's header row repeats on the next page.
 *
 * Text is wrapped by an estimate of how many characters fit on a line of the column (existing line breaks kept,
 * overlong words broken), then grouped into rows of about {@see LINES} lines. Columns of one row advance in lockstep;
 * a column that has run out gets empty cells. No row but a lone one has fewer than {@see MIN_LINES} lines, so a page
 * never starts or ends on a stray line or two.
 */
class CounselingFormRows
{
    /** Characters per line at 8.5pt DejaVu Sans on A4 with 18 mm margins: one quarter column, three quarters. */
    public const NARROW = 22;

    public const WIDE = 72;

    public const LINES = 9;

    public const MIN_LINES = 3;

    /**
     * @param  list<string|null>  $columns  the text of each column of the row
     * @return list<list<string>> rows, each with one text per column (lines joined by "\n")
     */
    public static function rows(array $columns, int $width): array
    {
        $wrapped = array_map(fn (?string $text) => self::wrap((string) $text, $width), $columns);
        $height = max(1, ...array_map('count', $wrapped));

        $rows = [];
        foreach (self::ranges($height) as [$from, $length]) {
            $rows[] = array_map(fn (array $lines) => implode("\n", array_slice($lines, $from, $length)), $wrapped);
        }

        return $rows;
    }

    /**
     * Line ranges of the rows: groups of LINES, the last merged into the one before when it is under MIN_LINES.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function ranges(int $height): array
    {
        $ranges = [];
        for ($from = 0; $from < $height; $from += self::LINES) {
            $ranges[] = [$from, min(self::LINES, $height - $from)];
        }

        $last = count($ranges) - 1;
        if ($last > 0 && $ranges[$last][1] < self::MIN_LINES) {
            $ranges[$last - 1][1] += $ranges[$last][1];
            array_pop($ranges);
        }

        return $ranges;
    }

    /**
     * @return list<string>
     */
    public static function wrap(string $text, int $width): array
    {
        $text = rtrim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return [];
        }

        $lines = [];
        foreach (explode("\n", $text) as $paragraph) {
            $line = '';
            foreach (preg_split('/[ \t]+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                while (mb_strlen($word) > $width) {
                    if ($line !== '') {
                        $lines[] = $line;
                        $line = '';
                    }
                    $lines[] = mb_substr($word, 0, $width);
                    $word = mb_substr($word, $width);
                }
                if ($word === '') {
                    continue;
                }
                if ($line === '') {
                    $line = $word;
                } elseif (mb_strlen($line) + 1 + mb_strlen($word) <= $width) {
                    $line .= ' '.$word;
                } else {
                    $lines[] = $line;
                    $line = $word;
                }
            }
            $lines[] = $line;
        }

        return $lines;
    }
}
