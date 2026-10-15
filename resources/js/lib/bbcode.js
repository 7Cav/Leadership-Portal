// The rollcall BBCode renderer (Story 3.10, AD-17, AD-20). It turns a company's active template and the week's marks into the
// BBCode a leader copies into the forum thread. It runs in the browser so the preview follows every toggle without a
// round trip, and it makes no outbound call of its own: the portal never posts to the forum.
//
// The template language is deliberately small and cannot run code. It is the same as Mustache's core, nothing more:
//   {{name}} and {{name|upper}} (or |lower)   print a value
//   {{#name}} ... {{/name}}                    repeat for each trooper (`troopers`), or show when a flag or value is set
//   {{^name}} ... {{/name}}                    show when it is not set (use {{^troopers}} for an empty section)
// A value is set when it is not empty and not a count of zero, so {{^attended}} can say that nobody attended.
// Every name is from the fixed lists below, so a typo is refused when an Admin saves the template (the server checks
// the same grammar, `Rollcall\TemplateSyntax`, against the shared cases in tests/Fixtures/bbcode-templates.json).
//
// A value that contains "[" would be read by the forum as a tag, so it is written with a zero-width space after it. A
// username or billet can therefore never open or close BBCode.

import { insigniaWidth } from './rankInsignia';

/** Names printable anywhere in a template. */
export const TOP_STRINGS = ['unit', 'unit_name', 'week', 'week_start', 'week_end', 'count', 'signed', 'attended'];
/** The one list a template may repeat over. */
export const LISTS = ['troopers'];
/** Names printable inside {{#troopers}}. */
export const ROW_STRINGS = ['n', 'role', 'rank', 'rank_short', 'rank_icon', 'rank_icon_width', 'name', 'display_name', 'rc', 'sp'];
/** Yes-or-no names, usable only as sections, inside {{#troopers}}. */
export const ROW_FLAGS = ['sp_present', 'sp_missed', 'sp_makeup', 'sp_attached', 'sp_loa', 'sp_unmarked', 'rc_yes', 'rc_no', 'rc_unsigned', 'is_sl', 'is_asl', 'is_ftl'];
export const FILTERS = ['upper', 'lower'];

// What each name means, and an example: what a value prints, or how a section or filter is written. For the legend on
// Settings > Templates, which is built from the lists above; a test checks every listed name has an entry here and no
// entry is left over, so the legend cannot drift from what the parser accepts.
const MEANINGS = {
    unit: ["The section's callsign", '1/E/2-7'],
    unit_name: ["The section's name", '1st Section'],
    week: ['The Cav week, Saturday to Friday', '16May26 – 22May26'],
    week_start: ['The Saturday the week starts', '16May26'],
    week_end: ['The Friday the week ends', '22May26'],
    count: ['How many troopers are in the section', '8'],
    signed: ['How many signed the rollcall as will attend', '6'],
    attended: ['How many were present, made up or attached at practice', '5'],
    troopers: ['Repeats what is inside once for each trooper. {{^troopers}}...{{/troopers}} shows when the section is empty', '{{#troopers}}{{n}}. {{display_name}}{{/troopers}}'],
    n: ["The trooper's row number, from 1. The list runs SL, ASL, fireteam leaders when the section shows them, then everyone else, each by rank", '1'],
    role: ["The trooper's role in the section: SL, ASL, or FTL when the section shows fireteam leaders. Empty for everyone else", 'SL'],
    rank: ['The full rank', 'Sergeant'],
    rank_short: ['The short rank', 'SGT'],
    rank_icon: ['The rank insignia image from Cav, for an [IMG] tag. Empty when Cav has none, so wrap it in {{#rank_icon}}...{{/rank_icon}}', '{{#rank_icon}}[IMG]{{rank_icon}}[/IMG]{{/rank_icon}}'],
    rank_icon_width: ['The width to show the rank insignia at, such as 11px, so every rank looks the same size. Empty when Cav has no insignia', '[IMG width="{{rank_icon_width}}" height="auto"]{{rank_icon}}[/IMG]'],
    name: ['The forum username', 'Jay.JH'],
    display_name: ['Short rank and username, as the forum names a member', 'SGT Jay.JH'],
    rc: ['The rollcall answer: Yes or No, empty when unsigned', 'Yes'],
    sp: ['The practice mark: Yes, No, MU, Att or LOA, empty when not marked', 'MU'],
    sp_present: ['Present at section practice', '{{#sp_present}}✓{{/sp_present}}'],
    sp_missed: ['Missed section practice', '{{#sp_missed}}✗{{/sp_missed}}'],
    sp_makeup: ['Made up a missed practice', '{{#sp_makeup}}MU{{/sp_makeup}}'],
    sp_attached: ['Trained with another unit, credited to this section', '{{#sp_attached}}ATT{{/sp_attached}}'],
    sp_loa: ['On leave for the week', '{{#sp_loa}}LOA{{/sp_loa}}'],
    sp_unmarked: ['No practice mark yet', '{{#sp_unmarked}}-{{/sp_unmarked}}'],
    rc_yes: ['Signed the rollcall: will attend', '{{#rc_yes}}✓{{/rc_yes}}'],
    rc_no: ['Signed the rollcall: cannot attend', '{{#rc_no}}✗{{/rc_no}}'],
    rc_unsigned: ['Has not signed the rollcall', '{{#rc_unsigned}}-{{/rc_unsigned}}'],
    is_sl: ['The Section Leader', '{{#is_sl}}(SL) {{/is_sl}}'],
    is_asl: ['The Assistant Section Leader', '{{#is_asl}}(ASL) {{/is_asl}}'],
    is_ftl: ['A fireteam leader, when the section shows fireteam leaders', '{{#is_ftl}}(FTL) {{/is_ftl}}'],
    upper: ['Prints a value in capitals', '{{week|upper}}'],
    lower: ['Prints a value in lower case', '{{unit|lower}}'],
};

/** The names the legend describes, so a test can hold them to the lists. */
export const DESCRIBED = Object.keys(MEANINGS);

/**
 * Every code the template language accepts, grouped as the parser reads them, each with a one-line meaning and an
 * example. Built from the same lists as {@link parse}. `where` says where a group's codes may be used, if anywhere
 * in particular. `open` and `close` are what the editor puts in when the code is clicked: a section's open and close
 * tags go around the selection, and any other code has an empty `close`.
 *
 * @returns {{key: string, title: string, where: string, entries: {name: string, code: string, open: string, close: string, meaning: string, example: string}[]}[]}
 */
export function templateLegend() {
    const group = (key, title, where, names, codeOf) => ({
        key,
        title,
        where,
        entries: names.map((name) => ({ name, ...codeOf(name), meaning: MEANINGS[name]?.[0] ?? '', example: MEANINGS[name]?.[1] ?? '' })),
    });
    const single = (code) => ({ code, open: code, close: '' });
    const tag = (name) => single(`{{${name}}}`);
    const block = (name) => ({ code: `{{#${name}}}...{{/${name}}}`, open: `{{#${name}}}`, close: `{{/${name}}}` });

    return [
        group('values', 'Values', 'Anywhere in the template', TOP_STRINGS, tag),
        group('lists', 'The trooper list', '', LISTS, block),
        group('rowValues', 'Trooper values', 'Inside {{#troopers}}', ROW_STRINGS, tag),
        group('rowFlags', 'Yes/no flags', 'Inside {{#troopers}}', ROW_FLAGS, block),
        group('filters', 'Filters', 'After a value', FILTERS, (name) => single(`{{value|${name}}}`)),
    ];
}

// The forum's own rank insignia, for the sample section's rows.
const SAMPLE_ICON = (file) => `https://7cav.us/data/roster_ranks/0/${file}`;

/**
 * A made-up section to fill a template with, for the preview on Settings > Templates. The section and the first trooper
 * are the legend's own examples; the other rows show the other marks, and one has no rank insignia. The totals are
 * counted from the rows, as {@link rollcallContext} counts them.
 */
export function sampleContext() {
    const example = (name) => MEANINGS[name][1];
    const row = (n, role, rank, rankShort, icon, name, rc, sp) => ({
        n: String(n),
        role,
        is_sl: role === 'SL',
        is_asl: role === 'ASL',
        is_ftl: role === 'FTL',
        rank,
        rank_short: rankShort,
        rank_icon: icon ? SAMPLE_ICON(icon) : '',
        rank_icon_width: icon ? `${insigniaWidth(SAMPLE_ICON(icon))}px` : '',
        name,
        display_name: `${rankShort} ${name}`,
        rc: { yes: 'Yes', no: 'No' }[rc] ?? '',
        sp: { present: 'Yes', missed: 'No', makeup: 'MU', attached: 'Att', loa: 'LOA' }[sp] ?? '',
        sp_present: sp === 'present',
        sp_missed: sp === 'missed',
        sp_makeup: sp === 'makeup',
        sp_attached: sp === 'attached',
        sp_loa: sp === 'loa',
        sp_unmarked: sp === null,
        rc_yes: rc === 'yes',
        rc_no: rc === 'no',
        rc_unsigned: rc === null,
    });
    const troopers = [
        row(1, example('role'), example('rank'), example('rank_short'), '18.jpg?1741376797', example('name'), 'yes', 'makeup'),
        row(2, 'FTL', 'Corporal', 'CPL', '19.jpg?1741376805', 'Adams.P', 'yes', 'present'),
        row(3, '', 'Specialist', 'SPC', '20.jpg?1741376814', 'Reyes.M', 'no', 'loa'),
        row(4, '', 'Private First Class', 'PFC', '21.jpg?1741376823', 'Novak.T', 'yes', 'attached'),
        row(5, '', 'Private', 'PVT', '22.jpg?1741376831', 'Okafor.B', null, null),
    ];

    return {
        unit: example('unit'),
        unit_name: example('unit_name'),
        week: example('week'),
        week_start: example('week_start'),
        week_end: example('week_end'),
        count: String(troopers.length),
        signed: String(troopers.filter((trooper) => trooper.rc_yes).length),
        attended: String(troopers.filter((trooper) => trooper.sp_present || trooper.sp_makeup || trooper.sp_attached).length),
        troopers,
    };
}

// One run of whitespace in each place, so a long run of spaces inside "{{ ... }}" cannot make the match slow.
const TAG = /\{\{\s*(?:([#^/])\s*)?([a-z_]+)\s*(?:\|\s*([a-z]+)\s*)?\}\}/g;

/**
 * Parse a template into a tree, or say what is wrong with it.
 *
 * @returns {{ok: true, tree: object[]} | {ok: false, error: string}}
 */
export function parse(source) {
    const root = [];
    const stack = [{ name: null, children: root }];
    let last = 0;

    const fail = (error) => ({ ok: false, error });
    const text = (to) => {
        const chunk = source.slice(last, to);
        if (chunk.includes('{{')) return fail('A "{{" with no matching "}}", or a tag that is not recognised.');
        if (chunk !== '') stack.at(-1).children.push({ type: 'text', value: chunk });

        return null;
    };

    for (const match of source.matchAll(TAG)) {
        const [whole, name, filter] = [match[0], match[2], match[3]];
        const mark = match[1] ?? '';
        const bad = text(match.index);
        if (bad) return bad;
        last = match.index + whole.length;

        const inRow = stack.some((frame) => frame.name === 'troopers');

        if (mark === '/') {
            if (stack.length === 1) return fail(`"{{/${name}}}" closes a section that is not open.`);
            if (stack.at(-1).name !== name) return fail(`"{{/${name}}}" does not match the open section "{{${stack.at(-1).inverted ? '^' : '#'}${stack.at(-1).name}}}".`);
            stack.pop();
            continue;
        }

        if (filter !== undefined && mark !== '') return fail(`A section "{{${mark}${name}}}" takes no filter.`);

        if (mark === '#' || mark === '^') {
            const known = LISTS.includes(name) || TOP_STRINGS.includes(name) || (inRow && (ROW_STRINGS.includes(name) || ROW_FLAGS.includes(name)));
            if (!known) return fail(unknownName(name, inRow));
            if (name === 'troopers' && inRow) return fail('"{{#troopers}}" cannot be nested inside itself.');

            const node = { type: 'section', name, inverted: mark === '^', children: [] };
            stack.at(-1).children.push(node);
            stack.push({ name, inverted: node.inverted, children: node.children });
            continue;
        }

        if (LISTS.includes(name)) return fail(`"${name}" cannot be printed; use {{#${name}}}...{{/${name}}} to repeat for each trooper.`);
        if (ROW_FLAGS.includes(name)) return fail(`"${name}" is a yes-or-no value, so use {{#${name}}}...{{/${name}}} rather than printing it.`);
        if (!(TOP_STRINGS.includes(name) || (inRow && ROW_STRINGS.includes(name)))) return fail(unknownName(name, inRow));
        if (filter !== undefined && !FILTERS.includes(filter)) return fail(`Unknown filter "${filter}"; use ${FILTERS.join(' or ')}.`);

        stack.at(-1).children.push({ type: 'value', name, filter });
    }

    const tail = text(source.length);
    if (tail) return tail;
    if (stack.length > 1) return fail(`The section "{{#${stack.at(-1).name}}}" is never closed.`);

    return { ok: true, tree: root };
}

function unknownName(name, inRow) {
    if (!inRow && (ROW_STRINGS.includes(name) || ROW_FLAGS.includes(name))) return `"${name}" can only be used inside {{#troopers}}.`;

    return `Unknown name "${name}".`;
}

/**
 * A value as the forum will show it: a "[" gets a zero-width space after it so it cannot open a tag, and a "]" is
 * written as a fullwidth "\uFF3D" so a value inside a tag option (`[URL={{name}}]`) cannot end the option early.
 */
export function inert(value) {
    return String(value).replaceAll('[', '[\u200B').replaceAll(']', '\uFF3D');
}

// The counts reach a template as text ("0"), so a zero count is not set either.
function truthy(value) {
    return value === true || (typeof value === 'string' && value !== '' && value !== '0') || (typeof value === 'number' && value !== 0);
}

function print(value, filter) {
    let out = inert(value ?? '');
    if (filter === 'upper') out = out.toUpperCase();
    if (filter === 'lower') out = out.toLowerCase();

    return out;
}

function renderNodes(nodes, scopes) {
    let out = '';
    const find = (name) => scopes.map((scope) => scope[name]).find((value) => value !== undefined);

    for (const node of nodes) {
        if (node.type === 'text') {
            out += node.value;
        } else if (node.type === 'value') {
            out += print(find(node.name), node.filter);
        } else if (node.name === 'troopers') {
            const rows = find('troopers') ?? [];
            if (node.inverted) {
                if (rows.length === 0) out += renderNodes(node.children, scopes);
            } else {
                rows.forEach((row) => {
                    out += renderNodes(node.children, [row, ...scopes]);
                });
            }
        } else if (truthy(find(node.name)) !== node.inverted) {
            out += renderNodes(node.children, scopes);
        }
    }

    return out;
}

// A toggle renders again with the same template, so the last parse is kept rather than redone.
let lastParse = { source: null, result: null };

function parseOnce(source) {
    if (lastParse.source !== source) lastParse = { source, result: parse(source) };

    return lastParse.result;
}

/**
 * Render a template with the week's data.
 *
 * @param {string} source the template body
 * @param {object} context `{ unit, unit_name, week, ..., troopers: [row] }`, as {@link rollcallContext} builds it
 * @returns {{ok: true, text: string} | {ok: false, error: string}}
 */
export function render(source, context) {
    const parsed = parseOnce(source);
    if (!parsed.ok) return parsed;

    return { ok: true, text: renderNodes(parsed.tree, [context]) };
}
