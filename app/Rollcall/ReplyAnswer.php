<?php

namespace App\Rollcall;

use App\Models\RollcallSignature;

/**
 * The answer a trooper's reply on the roll call thread gives, read from its keywords, case-insensitive, anywhere in the
 * text:
 * - "makeup" or "make-up": Make-up requested;
 * - "cannot attend", "can't attend" (a straight or a curly apostrophe) or "unable": Will not attend;
 * - "will attend", "attending" or "present": Will attend.
 * They are checked in that order, so "cannot attend" never reads as will attend, and "can't attend, I'll do a make-up"
 * is a make-up request. A keyword with "not", "no", "never" or a word ending in "n't" just before it (one word between
 * at most) does not count ("not attending", "won't be present", "no make-up needed"), so it is never read as the
 * opposite of what it says. Quoted text (`[QUOTE]`, usually the roll call post itself, which names every answer) and
 * the BBCode tags around words are left out first. A reply that matches none of them has no answer (null): the leader
 * reads it.
 */
final class ReplyAnswer
{
    /** The phrases of each answer (regular expressions over the plain text), in the order they are checked. */
    private const PHRASES = [
        'makeup_requested' => ['make-?ups?'],
        'cannot_attend' => ['cannot attend', 'can\'t attend', 'unable'],
        'will_attend' => ['will attend', 'attending', 'present'],
    ];

    /**
     * A negation just before a keyword, with at most one word between and nothing but spaces: "not", "no", "never", or
     * a word ending in "n't" ("not attending", "won't be attending"). Punctuation ends it, so "No problem, attending"
     * still reads as will attend.
     */
    private const NEGATED = '~\b(?:not|no|never|\w+n\'t)(?:\s+\w+)?\s*$~u';

    public static function read(string $bbcode): ?RollcallSignature
    {
        $text = self::plain($bbcode);

        foreach (self::PHRASES as $answer => $phrases) {
            foreach ($phrases as $phrase) {
                if (self::says($text, $phrase)) {
                    return RollcallSignature::from($answer);
                }
            }
        }

        return null;
    }

    /**
     * The reply as plain lower-case text: quotes removed (nested ones too), every other BBCode tag dropped with its
     * content kept, curly apostrophes made straight and runs of space made one.
     */
    private static function plain(string $bbcode): string
    {
        $text = $bbcode;

        // Innermost quote first, until none is left, so a quote inside a quote goes with it.
        do {
            $before = $text;
            $text = (string) preg_replace('~\[quote(?:=[^\]]*)?\](?:(?!\[quote(?:=[^\]]*)?\]).)*?\[/quote\]~isu', ' ', $text);
        } while ($text !== $before);

        $text = (string) preg_replace('~\[/?[a-z*][^\]]*\]~iu', '', $text);
        $text = str_replace(["\u{2019}", "\u{2018}", "\u{02BC}"], "'", $text);
        $text = (string) preg_replace('~\s+~u', ' ', $text);

        return mb_strtolower(trim($text));
    }

    /** Whether the text holds the phrase as whole words, at least once without a negation before it. */
    private static function says(string $text, string $phrase): bool
    {
        if (preg_match_all('~\b'.$phrase.'\b~u', $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return false;
        }

        foreach ($matches[0] as [, $offset]) {
            if (preg_match(self::NEGATED, substr($text, 0, $offset)) !== 1) {
                return true;
            }
        }

        return false;
    }
}
