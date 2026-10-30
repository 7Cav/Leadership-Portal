<?php

namespace App\Commands\Counseling;

/**
 * Reads an uploaded counseling PDF's raw bytes for what the portal will not keep (#381): a missing end marker, a
 * password, scripts, automatic actions, attachments and other active content. It never renders or runs anything.
 *
 * Names are matched as PDF name tokens after decoding `#xx` escapes, so `/J#61vaScript` counts as `/JavaScript`.
 * Compressed (`/FlateDecode`) streams are inflated too, because object streams can carry whole dictionaries; each
 * stream and the whole file have an output budget, so a small file that inflates to gigabytes is refused instead of
 * filling memory.
 *
 * What is refused and why:
 * - `/Encrypt`: a password or encryption hides the content from this check.
 * - `/JavaScript`, `/JS`: scripts.
 * - `/AA`: actions that run on their own when a page or field is opened, closed or changed.
 * - `/OpenAction` with an action (a dictionary or a reference) runs when the file opens. One that only names a page
 *   to open at (an array) is allowed, as many PDF writers add it.
 * - `/Launch`, `/GoToR`, `/GoToE`, `/SubmitForm`, `/ImportData`: start programs, open or send to other files and
 *   servers.
 * - `/EmbeddedFile`, `/EmbeddedFiles`, `/RichMedia`, `/XFA`: attachments, embedded media and XML forms.
 *
 * Plain web links (`/URI`) are allowed: they need a click, open in the reader's own browser, and signed forms often
 * carry one.
 */
final class PdfContentScanner
{
    /** How far from the end `%%EOF` may sit (the PDF spec's own allowance). */
    public const EOF_WINDOW = 1024;

    /** The most one stream may inflate to before the file is refused. */
    public const STREAM_BUDGET = 32 * 1024 * 1024;

    /** The most all streams together may inflate to. */
    public const TOTAL_BUDGET = 64 * 1024 * 1024;

    private const ANYWHERE = [
        'Encrypt' => self::ENCRYPTED,
        'JavaScript' => self::ACTIVE,
        'Launch' => self::ACTIVE,
        'GoToR' => self::ACTIVE,
        'GoToE' => self::ACTIVE,
        'SubmitForm' => self::ACTIVE,
        'ImportData' => self::ACTIVE,
        'EmbeddedFile' => self::ACTIVE,
        'EmbeddedFiles' => self::ACTIVE,
        'RichMedia' => self::ACTIVE,
        'XFA' => self::ACTIVE,
    ];

    /** Short keys, refused only as a dictionary key with a value, so a stray byte run in image data does not count. */
    private const AS_KEY = ['JS', 'AA'];

    public const ENCRYPTED = 'encrypted';

    public const ACTIVE = 'active';

    public const INCOMPLETE = 'incomplete';

    public const UNCHECKED = 'unchecked';

    /**
     * Why the PDF is refused, or null when it is fine.
     *
     * @return self::ENCRYPTED|self::ACTIVE|self::INCOMPLETE|self::UNCHECKED|null
     */
    public static function problem(string $bytes): ?string
    {
        if (! str_contains(substr($bytes, -self::EOF_WINDOW), '%%EOF')) {
            return self::INCOMPLETE;
        }

        $found = self::scan($bytes);
        if ($found !== null) {
            return $found;
        }

        $total = 0;
        foreach (self::flateStreams($bytes) as $data) {
            $inflated = self::inflate($data, min(self::STREAM_BUDGET, self::TOTAL_BUDGET - $total));
            if ($inflated === null) {
                return self::UNCHECKED;
            }
            $total += strlen($inflated);

            $found = self::scan($inflated);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** The first refused name token in `$bytes`. */
    private static function scan(string $bytes): ?string
    {
        // A name is `/` then any run of regular characters (not whitespace or a delimiter).
        if (preg_match_all('~/([^\x00\x09\x0a\x0c\x0d\x20()<>\[\]{}/%]*)~', $bytes, $matches, PREG_OFFSET_CAPTURE) === false) {
            return self::UNCHECKED;
        }

        foreach ($matches[1] as [$raw, $offset]) {
            $name = str_contains($raw, '#')
                ? (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn (array $m) => chr((int) hexdec($m[1])), $raw)
                : $raw;
            $after = substr($bytes, $offset + strlen($raw), 64);

            if (isset(self::ANYWHERE[$name])) {
                return self::ANYWHERE[$name];
            }

            if (in_array($name, self::AS_KEY, true) && preg_match('/^\s*(?:<<|<|\(|\[|\d+\s+\d+\s+R)/', $after) === 1) {
                return self::ACTIVE;
            }

            if ($name === 'OpenAction' && preg_match('/^\s*(?:<<|\d+\s+\d+\s+R)/', $after) === 1) {
                return self::ACTIVE;
            }
        }

        return null;
    }

    /**
     * The raw data of every stream whose dictionary names `/FlateDecode`.
     *
     * @return iterable<string>
     */
    private static function flateStreams(string $bytes): iterable
    {
        if (preg_match_all('/stream\r?\n/', $bytes, $starts, PREG_OFFSET_CAPTURE) === false) {
            return;
        }

        foreach ($starts[0] as [$keyword, $offset]) {
            // Skip the `stream` inside `endstream`.
            if ($offset >= 3 && substr($bytes, $offset - 3, 3) === 'end') {
                continue;
            }

            $dictionary = substr($bytes, max(0, $offset - 1024), min(1024, $offset));
            $open = strrpos($dictionary, 'obj');
            $dictionary = $open === false ? $dictionary : substr($dictionary, $open);
            if (preg_match('~/(?:FlateDecode|Fl)\b~', self::decodeNames($dictionary)) !== 1) {
                continue;
            }

            $begin = $offset + strlen($keyword);
            $end = strpos($bytes, 'endstream', $begin);

            yield substr($bytes, $begin, ($end === false ? strlen($bytes) : $end) - $begin);
        }
    }

    private static function decodeNames(string $text): string
    {
        return (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn (array $m) => chr((int) hexdec($m[1])), $text);
    }

    /**
     * Inflate `$data` a slice at a time, stopping at `$budget` bytes of output (null: over budget). Data that is not
     * valid zlib yields what decoded before the error, as a lenient reader would show that much too.
     */
    private static function inflate(string $data, int $budget): ?string
    {
        if ($budget <= 0) {
            return null;
        }

        $context = inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($context === false) {
            return '';
        }

        $out = '';
        foreach (str_split($data, 4096) as $slice) {
            $chunk = @inflate_add($context, $slice, ZLIB_SYNC_FLUSH);
            if ($chunk === false) {
                break;
            }
            $out .= $chunk;
            if (strlen($out) > $budget) {
                return null;
            }
            if (inflate_get_status($context) === ZLIB_STREAM_END) {
                break;
            }
        }

        return $out;
    }
}
