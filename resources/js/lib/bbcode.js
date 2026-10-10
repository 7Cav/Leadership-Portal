// The rollcall BBCode renderer (Story 3.10, AD-17, AD-20). It turns a company's active template and the week's marks into the
// BBCode a leader copies into the forum thread. It runs in the browser so the preview follows every toggle without a
// round trip, and it makes no outbound call of its own: the portal never posts to the forum.
//
// The template language is deliberately small and cannot run code. It is the same as Mustache's core, nothing more:
//   {{name}} and {{name|upper}} (or |lower)   print a value
//   {{#name}} ... {{/name}}                    repeat for each trooper (`troopers`), or show when a flag or value is set
//   {{^name}} ... {{/name}}                    show when it is not set (use {{^troopers}} for an empty section)
// Every name is from the fixed lists below, so a typo is refused when an Admin saves the template (the server checks
// the same grammar, `Rollcall\TemplateSyntax`, against the shared cases in tests/fixtures/bbcode-templates.json).
//
// A value that contains "[" would be read by the forum as a tag, so it is written with a zero-width space after it. A
// username or billet can therefore never open or close BBCode.

import { forumDate } from './forumDate';

/** Names printable anywhere in a template. */
export const TOP_STRINGS = ['unit', 'unit_name', 'week', 'week_start', 'week_end', 'count', 'signed', 'attended'];
/** The one list a template may repeat over. */
export const LISTS = ['troopers'];
/** Names printable inside {{#troopers}}. */
export const ROW_STRINGS = ['n', 'rank', 'rank_short', 'name', 'display_name', 'role', 'rc', 'sp', 'loa_ends', 'loa_type'];
/** Yes-or-no names, usable only as sections, inside {{#troopers}}. */
export const ROW_FLAGS = ['sp_present', 'sp_missed', 'sp_makeup', 'sp_attached', 'sp_loa', 'sp_unmarked', 'rc_yes', 'rc_no', 'rc_unsigned', 'loa_review'];
export const FILTERS = ['upper', 'lower'];

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

/** A value as the forum will show it: a leading "[" is made inert so it cannot become a tag. */
export function inert(value) {
    return String(value).replaceAll('[', '[\u200B');
}

function truthy(value) {
    return value === true || (typeof value === 'string' && value !== '') || (typeof value === 'number' && value !== 0);
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

const SP_WORDS = { present: 'Yes', missed: 'No', makeup: 'MU', attached: 'Att', loa: 'LOA' };
const RC_WORDS = { will_attend: 'Yes', cannot_attend: 'No' };

/**
 * The values a rollcall template can print, from the section, the week and each trooper's current choices.
 *
 * @param {object} screen `{ section, week, troopers }`: the props the rollcall page received
 * @param {Record<number, {sp: ?string, rc: ?string}>} draft what the leader has chosen so far, by trooper id
 * @param {(a: object, b: object) => number} order the senior-first comparison the page lists rows by
 */
export function rollcallContext(screen, draft, order) {
    const { section, week } = screen;
    const rows = [...screen.troopers].sort(order).map((row, index) => {
        const onLeave = row.practice.origin === 'leave';
        const sp = onLeave ? 'loa' : (draft[row.id]?.sp ?? null);
        const rc = draft[row.id]?.rc ?? null;
        const covered = onLeave && row.loa.endsAt ? forumDate(row.loa.endsAt) : '';

        return {
            n: String(index + 1),
            rank: row.rank ?? '',
            rank_short: row.rankShort ?? '',
            name: row.username ?? row.displayName,
            display_name: row.displayName,
            role: row.billet ?? '',
            rc: RC_WORDS[rc] ?? '',
            sp: SP_WORDS[sp] ?? '',
            loa_ends: covered,
            loa_type: onLeave ? (row.loa.typeLabel ?? 'LOA') : '',
            sp_present: sp === 'present',
            sp_missed: sp === 'missed',
            sp_makeup: sp === 'makeup',
            sp_attached: sp === 'attached',
            sp_loa: sp === 'loa',
            sp_unmarked: sp === null,
            rc_yes: rc === 'will_attend',
            rc_no: rc === 'cannot_attend',
            rc_unsigned: rc === null,
            loa_review: row.loa.state === 'needs_review',
        };
    });

    return {
        unit: section.callsign ?? section.name,
        unit_name: section.name,
        week: `${forumDate(week.start)} – ${forumDate(week.end)}`,
        week_start: forumDate(week.start),
        week_end: forumDate(week.end),
        count: String(rows.length),
        signed: String(rows.filter((row) => row.rc_yes).length),
        attended: String(rows.filter((row) => row.sp_present || row.sp_makeup || row.sp_attached).length),
        troopers: rows,
    };
}
