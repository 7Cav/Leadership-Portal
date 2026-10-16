<?php

namespace App\Rollcall;

/**
 * The grammar of a rollcall template (Story 3.10, AD-17), checked when an Admin saves one so a typo is refused instead
 * of reaching every section's rollcall. The renderer is `resources/js/lib/bbcode.js`, which runs in the browser so the
 * preview follows each toggle; this is its twin for validation only. Both read the shared cases in
 * `tests/Fixtures/bbcode-templates.json`, so they accept and refuse the same templates with the same messages.
 *
 * The language is Mustache's core and nothing more, so it cannot run code:
 *   {{name}} and {{name|upper}}   print a value
 *   {{#name}} ... {{/name}}       repeat for each trooper (`troopers`), or show when a flag or value is set
 *   {{^name}} ... {{/name}}       show when it is not set
 * Every name comes from the fixed lists below.
 */
final class TemplateSyntax
{
    /** Names printable anywhere in a template. */
    public const TOP_STRINGS = ['unit', 'unit_name', 'week', 'week_start', 'week_end', 'count', 'signed', 'attended'];

    /** Yes-or-no names about the whole post, usable only as sections, anywhere in a template. */
    public const TOP_FLAGS = ['fireteams_shown'];

    /** The one list a template may repeat over. */
    public const LISTS = ['troopers'];

    /** Names printable inside {{#troopers}}. */
    public const ROW_STRINGS = ['n', 'role', 'rank', 'rank_short', 'rank_icon', 'rank_icon_width', 'name', 'display_name', 'rc', 'sp'];

    /** Yes-or-no names, usable only as sections, inside {{#troopers}}. */
    public const ROW_FLAGS = ['sp_present', 'sp_missed', 'sp_makeup', 'sp_attached', 'sp_loa', 'sp_unmarked', 'rc_yes', 'rc_no', 'rc_unsigned', 'is_sl', 'is_asl', 'is_ftl'];

    public const FILTERS = ['upper', 'lower'];

    /**
     * A template an Admin can start from when a company has none: the mockup's post, with a table of troopers and a
     * mark for each status, each trooper's rank insignia before their name. `{{rank_icon}}` is empty when Cav sends no
     * insignia, so the [IMG] tag sits in a `{{#rank_icon}}` section and prints nothing then. `{{rank_icon_width}}` sizes
     * each insignia so every rank looks the same size on the forum, which scales the height to match. It follows the
     * grammar, which a test checks. A Role column (Section Leader, Assistant Section Leader, FTL Alpha) shows only when the
     * section shows fireteam leaders and has at least one (`{{#fireteams_shown}}`); otherwise the table is as before.
     */
    public const STARTER = <<<'BBCODE'
[CENTER]
[B]{{unit}} ROLL CALL | {{week|upper}}[/B]
[/CENTER]

[B]Roll Call Accountability[/B]
[TABLE]
[TR][TH]#[/TH][TH]Name[/TH]{{#fireteams_shown}}[TH]Role[/TH]{{/fireteams_shown}}[TH]Signed RC[/TH][TH]Attended SP[/TH][/TR]
{{#troopers}}[TR][TD]{{n}}[/TD][TD]{{#rank_icon}}[IMG width="{{rank_icon_width}}" height="auto"]{{rank_icon}}[/IMG] {{/rank_icon}}{{rank}} {{name}}[/TD]{{#fireteams_shown}}[TD]{{role}}[/TD]{{/fireteams_shown}}[TD]{{#rc_yes}}✓{{/rc_yes}}{{#rc_no}}✗{{/rc_no}}{{#rc_unsigned}}-{{/rc_unsigned}}[/TD][TD]{{#sp_present}}✓{{/sp_present}}{{#sp_missed}}✗{{/sp_missed}}{{#sp_makeup}}MU{{/sp_makeup}}{{#sp_attached}}ATT{{/sp_attached}}{{#sp_loa}}LOA{{/sp_loa}}{{#sp_unmarked}}-{{/sp_unmarked}}[/TD][/TR]
{{/troopers}}{{^troopers}}[TR][TD]No troopers are placed in this section.[/TD][/TR]
{{/troopers}}[/TABLE]
BBCODE;

    /** One run of whitespace in each place, so a long run of spaces inside "{{ ... }}" cannot make the match slow. */
    private const TAG = '/\{\{\s*(?:([#^\/])\s*)?([a-z_]+)\s*(?:\|\s*([a-z]+)\s*)?\}\}/';

    /**
     * Null when the template is well formed, otherwise a sentence saying what is wrong.
     */
    public static function check(string $source): ?string
    {
        // A regex that fails (a backtrack or JIT limit) must never read as a valid template.
        if (preg_match_all(self::TAG, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return 'This template could not be checked.';
        }

        /** @var list<array{name: string, inverted: bool}> $stack */
        $stack = [];
        $last = 0;

        foreach ($matches as $match) {
            [$whole, $offset] = $match[0];
            $mark = $match[1][0];
            $name = $match[2][0];
            $filter = isset($match[3]) && $match[3][1] !== -1 ? $match[3][0] : null;

            if (str_contains(substr($source, $last, $offset - $last), '{{')) {
                return 'A "{{" with no matching "}}", or a tag that is not recognised.';
            }

            $last = $offset + strlen($whole);
            $inRow = in_array('troopers', array_column($stack, 'name'), true);

            if ($mark === '/') {
                if ($stack === []) {
                    return "\"{{/{$name}}}\" closes a section that is not open.";
                }

                $open = end($stack);

                if ($open['name'] !== $name) {
                    return "\"{{/{$name}}}\" does not match the open section \"{{".($open['inverted'] ? '^' : '#')."{$open['name']}}}\".";
                }

                array_pop($stack);

                continue;
            }

            if ($filter !== null && $mark !== '') {
                return "A section \"{{{$mark}{$name}}}\" takes no filter.";
            }

            if ($mark === '#' || $mark === '^') {
                $known = in_array($name, self::LISTS, true)
                    || in_array($name, self::TOP_STRINGS, true)
                    || in_array($name, self::TOP_FLAGS, true)
                    || ($inRow && (in_array($name, self::ROW_STRINGS, true) || in_array($name, self::ROW_FLAGS, true)));

                if (! $known) {
                    return self::unknownName($name, $inRow);
                }

                if ($name === 'troopers' && $inRow) {
                    return '"{{#troopers}}" cannot be nested inside itself.';
                }

                $stack[] = ['name' => $name, 'inverted' => $mark === '^'];

                continue;
            }

            if (in_array($name, self::LISTS, true)) {
                return "\"{$name}\" cannot be printed; use {{#{$name}}}...{{/{$name}}} to repeat for each trooper.";
            }

            if (in_array($name, self::ROW_FLAGS, true) || in_array($name, self::TOP_FLAGS, true)) {
                return "\"{$name}\" is a yes-or-no value, so use {{#{$name}}}...{{/{$name}}} rather than printing it.";
            }

            if (! (in_array($name, self::TOP_STRINGS, true) || ($inRow && in_array($name, self::ROW_STRINGS, true)))) {
                return self::unknownName($name, $inRow);
            }

            if ($filter !== null && ! in_array($filter, self::FILTERS, true)) {
                return "Unknown filter \"{$filter}\"; use ".implode(' or ', self::FILTERS).'.';
            }
        }

        if (str_contains(substr($source, $last), '{{')) {
            return 'A "{{" with no matching "}}", or a tag that is not recognised.';
        }

        if ($stack !== []) {
            return 'The section "{{#'.end($stack)['name'].'}}" is never closed.';
        }

        return null;
    }

    private static function unknownName(string $name, bool $inRow): string
    {
        if (! $inRow && (in_array($name, self::ROW_STRINGS, true) || in_array($name, self::ROW_FLAGS, true))) {
            return "\"{$name}\" can only be used inside {{#troopers}}.";
        }

        return "Unknown name \"{$name}\".";
    }
}
