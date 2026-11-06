<?php

namespace App\Support\Text;

/**
 * Cleans free text typed into the portal. Bidi controls are removed, so what a page shows is what was stored. A value
 * made only of whitespace or invisible characters (a Braille blank, zero-width spaces, a Hangul filler) becomes '',
 * so `required` refuses it and ConvertEmptyStringsToNull nulls an optional one. A zero-width joiner inside real text
 * stays: emoji need it.
 */
final class FreeText
{
    private const BIDI_CONTROLS = '/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}\x{061C}]/u';

    private const ONLY_INVISIBLE = '/^[\s\p{Z}\x{2800}\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{3164}\x{115F}\x{1160}\x{00AD}]*$/u';

    public static function clean(string $value): string
    {
        $stripped = preg_replace(self::BIDI_CONTROLS, '', $value);

        // Not valid UTF-8: leave it to the validation rules rather than guess.
        if ($stripped === null) {
            return $value;
        }

        return preg_match(self::ONLY_INVISIBLE, $stripped) === 1 ? '' : trim($stripped);
    }
}
