<?php

namespace App\Models;

use IntlChar;
use Normalizer;

/**
 * How a game identity's player name and ID are cleaned before they are stored (#450), so what a leader sees is what
 * the capture matches: control characters and invisible format characters (zero-width spaces and joiners, the byte
 * order mark, direction marks, overrides and isolates, the soft hyphen, line and paragraph separators, the Hangul
 * fillers) are removed, the text is normalised to Unicode NFC, and surrounding spaces are trimmed. Bytes that are not
 * UTF-8 become "?" first. Printable ASCII is never changed, so an ID a game server reported cleans to itself.
 *
 * Also tells whether a name mixes alphabets (Latin with Cyrillic, say), so a look-alike can be flagged for a leader
 * to check. Both need PHP's intl extension, which the image installs; without it the text is still cleaned of
 * hidden characters, only not normalised, and no name is flagged.
 */
final class GameIdentityText
{
    /** Cc control, Cf format, Zl and Zp separators, and the Hangul fillers that render as nothing. */
    private const HIDDEN = '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}\x{115F}\x{1160}\x{3164}\x{FFA0}]/u';

    /** @var array<int, int>|null script code => the group it counts as, for the scripts one writing system mixes */
    private static ?array $groups = null;

    public static function clean(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($text, 'UTF-8');
        }

        $text = preg_replace(self::HIDDEN, '', $text) ?? $text;

        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        }

        return preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $text) ?? trim($text);
    }

    /**
     * The text with each hidden character written out as its code point ("[U+200B]"), so an Admin can see what a
     * stored name or ID holds. For display only.
     */
    public static function reveal(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($text, 'UTF-8');
        }

        return preg_replace_callback(self::HIDDEN, fn (array $m) => sprintf('[U+%04X]', mb_ord($m[0])), $text) ?? $text;
    }

    /** Whether the text is already as {@see self::clean()} would store it. */
    public static function isClean(string $text): bool
    {
        return self::clean($text) === $text;
    }

    /**
     * Whether the letters of `$text` come from more than one script. Digits, punctuation, symbols and combining marks
     * (the Common and Inherited scripts) are ignored, and Han, Hiragana, Katakana, Hangul and Bopomofo count as one,
     * since Japanese and Korean names mix them as a matter of course.
     */
    public static function mixesScripts(string $text): bool
    {
        if (! class_exists(IntlChar::class) || ! mb_check_encoding($text, 'UTF-8')) {
            return false;
        }

        $groups = self::groups();
        $seen = [];

        foreach (mb_str_split($text) as $char) {
            $script = IntlChar::getIntPropertyValue(IntlChar::ord($char), IntlChar::PROPERTY_SCRIPT);

            if (isset($groups[$script]) && $groups[$script] === -1) {
                continue;
            }

            $seen[$groups[$script] ?? $script] = true;

            if (count($seen) > 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, int> -1 for a script that is ignored */
    private static function groups(): array
    {
        if (self::$groups !== null) {
            return self::$groups;
        }

        $code = fn (string $name): int => IntlChar::getPropertyValueEnum(IntlChar::PROPERTY_SCRIPT, $name);
        $groups = [];

        foreach (['Common', 'Inherited', 'Unknown'] as $name) {
            $groups[$code($name)] = -1;
        }

        foreach (['Han', 'Hiragana', 'Katakana', 'Hangul', 'Bopomofo'] as $name) {
            $groups[$code($name)] = $code('Han');
        }

        return self::$groups = $groups;
    }
}
